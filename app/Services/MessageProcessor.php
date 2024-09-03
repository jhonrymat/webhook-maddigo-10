<?php

namespace App\Services;

use App\Models\Message;
use App\Models\Contacto;
use App\Libraries\Whatsapp;
use Illuminate\Support\Facades\Log;

class MessageProcessor
{
    /**
     * Procesa los mensajes entrantes.
     *
     * @param array $value Contenido del mensaje recibido.
     */
    public function processMessage($value)
    {
        if (!empty($value['messages'])) {
            Log::info('Mensaje recibido: ' . json_encode($value['messages'][0]));

            // Encuentra o crea un contacto
            $contact = $this->findOrCreateContact($value['contacts'][0]);

            // Verifica si el mensaje ya existe
            $messageExists = $this->messageExists($value['messages'][0]['id']);

            if (!$messageExists) {
                // Manejar el mensaje basado en su tipo
                $this->handleMessage($value['messages'][0], $value['metadata']['phone_number_id']);
            }
        }
    }

    /**
     * Procesa los estados de los mensajes.
     *
     * @param array $value Contenido del estado recibido.
     */
    public function processStatus($value)
    {
        if (!empty($value['statuses'])) {
            Log::info('Estado recibido: ' . json_encode($value['statuses'][0]));
            $message = Message::where('wam_id', $value['statuses'][0]['id'])->first();

            if ($message) {
                $message->status = $value['statuses'][0]['status'];
                $message->save();

                // Dispara el evento Webhook con la actualización del estado
                Webhook::dispatch($message, true);
            }
        }
    }

    /**
     * Encuentra un contacto por su número de teléfono, o lo crea si no existe.
     *
     * @param array $contactData Datos del contacto recibido.
     * @return Contacto El contacto encontrado o creado.
     */
    private function findOrCreateContact($contactData)
    {
        $contact = Contacto::where('telefono', $contactData['wa_id'])->first();

        if (!$contact) {
            // Crea un nuevo contacto si no existe
            $contact = new Contacto();
            $contact->telefono = $contactData['wa_id'];
            $contact->nombre = $contactData['profile']['name'];
            $contact->notas = "Contacto creado por webhook";
            $contact->save();

            // Asigna etiquetas u otras operaciones
            $contact->tags()->attach(22);
        } else if ($contact->nombre == $contact->telefono) {
            // Actualiza el nombre del contacto si es necesario
            $contact->nombre = $contactData['profile']['name'];
            $contact->notas = "Nombre actualizado por webhook";
            $contact->save();
        }

        return $contact;
    }

    /**
     * Verifica si un mensaje ya existe en la base de datos.
     *
     * @param string $wamId ID del mensaje de WhatsApp.
     * @return bool Verdadero si el mensaje ya existe, falso de lo contrario.
     */
    private function messageExists($wamId)
    {
        return Message::where('wam_id', $wamId)->exists();
    }

    /**
     * Maneja un mensaje recibido según su tipo.
     *
     * @param array $messageData Datos del mensaje recibido.
     * @param string $phoneId ID del teléfono.
     */
    private function handleMessage($messageData, $phoneId)
    {
        $mediaSupported = ['audio', 'document', 'image', 'video', 'sticker'];

        if ($messageData['type'] == 'text') {
            // Procesar mensajes de texto
            $this->handleTextMessage($messageData, $phoneId);
        } elseif (in_array($messageData['type'], $mediaSupported)) {
            // Procesar mensajes multimedia
            $this->handleMediaMessage($messageData, $phoneId);
        } else {
            // Procesar otros tipos de mensajes
            $this->handleOtherMessage($messageData, $phoneId);
        }
    }

    /**
     * Maneja un mensaje de texto.
     *
     * @param array $messageData Datos del mensaje de texto.
     * @param string $phoneId ID del teléfono.
     */
    private function handleTextMessage($messageData, $phoneId)
    {
        $messageBody = $messageData['text']['body'];
        $userWaId = $messageData['from'];

        // Instancia el bot y obtiene la respuesta
        $botIA = new BotIA();
        $answer = $botIA->ask($messageBody, $userWaId);

        // Enviar respuesta del bot a WhatsApp
        $respuesta = new Whatsapp();
        $num = Numeros::where('id_telefono', $phoneId)->first();
        $app = Aplicaciones::where('id', $num->aplicacion_id)->first();
        $tk = $app->token_api;
        $response = $respuesta->sendText($userWaId, $answer, $num->id_telefono, $tk);

        // Guarda el mensaje en la base de datos
        $this->saveMessage(
            $answer,
            'text',
            $userWaId,
            $response["messages"][0]["id"],
            $num->id_telefono,
            Carbon::now()->timestamp
        );
    }

    /**
     * Maneja un mensaje multimedia.
     *
     * @param array $messageData Datos del mensaje multimedia.
     * @param string $phoneId ID del teléfono.
     */
    private function handleMediaMessage($messageData, $phoneId)
    {
        $mediaType = $messageData['type'];
        $mediaId = $messageData[$mediaType]['id'];
        $wp = new Whatsapp();
        $num = Numeros::where('id_telefono', $phoneId)->first();
        $app = Aplicaciones::where('id', $num->aplicacion_id)->first();
        $tk = $app->token_api;
        $file = $wp->downloadMedia($mediaId, $tk);

        $caption = $messageData[$mediaType]['caption'] ?? null;

        if (!is_null($file)) {
            $this->saveMessage(
                env('APP_URL_MG') . '/storage/' . $file,
                $mediaType,
                $messageData['from'],
                $messageData['id'],
                $phoneId,
                $messageData['timestamp'],
                $caption
            );
        }
    }

    /**
     * Maneja otros tipos de mensajes.
     *
     * @param array $messageData Datos del mensaje recibido.
     * @param string $phoneId ID del teléfono.
     */
    private function handleOtherMessage($messageData, $phoneId)
    {
        $type = $messageData['type'];
        if (!empty($messageData[$type])) {
            $this->saveMessage(
                "($type): \n _" . serialize($messageData[$type]) . "_",
                'other',
                $messageData['from'],
                $messageData['id'],
                $phoneId,
                $messageData['timestamp']
            );
        }
    }

    /**
     * Guarda un mensaje en la base de datos.
     *
     * @param string $message Contenido del mensaje.
     * @param string $messageType Tipo de mensaje (texto, imagen, etc.).
     * @param string $waId ID del remitente en WhatsApp.
     * @param string $wamId ID del mensaje en WhatsApp.
     * @param string $phoneId ID del teléfono.
     * @param int|null $timestamp Marca de tiempo del mensaje.
     * @param string|null $caption Descripción del mensaje, si la hay.
     * @return Message El mensaje guardado.
     */
    private function saveMessage($message, $messageType, $waId, $wamId, $phoneId, $timestamp = null, $caption = null)
    {
        $wam = new Message();
        $wam->body = $message;
        $wam->outgoing = false;
        $wam->type = $messageType;
        $wam->wa_id = $waId;
        $wam->wam_id = $wamId;
        $wam->phone_id = $phoneId;
        $wam->status = 'sent';
        $wam->caption = $caption;

        if (!is_null($timestamp)) {
            $wam->created_at = Carbon::createFromTimestamp($timestamp)->toDateTimeString();
            $wam->updated_at = Carbon::createFromTimestamp($timestamp)->toDateTimeString();
        }
        $wam->save();

        Webhook::dispatch($wam, false);

        return $wam;
    }
}
