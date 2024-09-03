<?php

namespace App\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\MessageProcessor;

class MessageController extends Controller
{
    protected $messageProcessor;

    /**
     * Constructor para inyectar el servicio MessageProcessor.
     *
     * @param MessageProcessor $messageProcessor
     */
    public function __construct(MessageProcessor $messageProcessor)
    {
        $this->messageProcessor = $messageProcessor;
    }

    /**
     * Verifica el webhook recibido.
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function verifyWebhook(Request $request)
    {
        try {
            // Obtiene el token de verificación desde el archivo de configuración
            $verifyToken = env('WHATSAPP_VERIFY_TOKEN');
            $query = $request->query();

            // Extrae los parámetros de la solicitud
            $mode = $query['hub_mode'];
            $token = $query['hub_verify_token'];
            $challenge = $query['hub_challenge'];

            // Verifica que el modo y el token sean correctos
            if ($mode && $token) {
                if ($mode === 'subscribe' && $token == $verifyToken) {
                    // Retorna la respuesta de desafío si la verificación es exitosa
                    return response($challenge, 200)->header('Content-Type', 'text/plain');
                }
            }

            // Lanza una excepción si la solicitud no es válida
            throw new Exception('Invalid request');
        } catch (Exception $e) {
            // Registra el error en el archivo de logs
            Log::error('Error al verificar el webhook: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Procesa el webhook recibido.
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function processWebhook(Request $request)
    {
        // Registra el contenido del payload para fines de depuración
        Log::info('processWebhook llamado con payload: ' . $request->getContent());

        try {
            // Decodifica el contenido de la solicitud entrante
            $bodyContent = json_decode($request->getContent(), true);
            $value = $bodyContent['entry'][0]['changes'][0]['value'];

            // Procesa los estados del mensaje si existen
            if (!empty($value['statuses'])) {
                $this->messageProcessor->processStatus($value);
            } elseif (!empty($value['messages'])) { // Procesa los mensajes entrantes
                $this->messageProcessor->processMessage($value);
            }

            // Devuelve una respuesta exitosa si todo se procesa correctamente
            return response()->json(['success' => true], 200);
        } catch (Exception $e) {
            // Registra el error y el stack trace para depuración
            Log::error('Error al procesar el webhook: ' . $e->getMessage());
            Log::error('Detalle de la excepción: ' . $e->getTraceAsString());
            Log::error('Contenido de la solicitud con error: ' . $request->getContent());

            // Retorna una respuesta de error
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Método privado para guardar mensajes.
     * Este método se utiliza para registrar mensajes en la base de datos.
     *
     * @param string $message Contenido del mensaje.
     * @param string $messageType Tipo de mensaje (texto, imagen, etc.).
     * @param string $waId ID del remitente en WhatsApp.
     * @param string $wamId ID del mensaje en WhatsApp.
     * @param string $phoneId ID del teléfono.
     * @param int|null $timestamp Marca de tiempo del mensaje.
     * @param string|null $caption Descripción del mensaje, si la hay.
     * @param string $data Datos adicionales del mensaje.
     * @return Message El mensaje guardado.
     */
    private function _saveMessage($message, $messageType, $waId, $wamId, $phoneId, $timestamp = null, $caption = null, $data = '')
    {
        // Crea una nueva instancia del modelo Message
        $wam = new Message();
        $wam->body = $message;
        $wam->outgoing = false;
        $wam->type = $messageType;
        $wam->wa_id = $waId;
        $wam->wam_id = $wamId;
        $wam->phone_id = $phoneId;
        $wam->status = 'sent';
        $wam->caption = $caption;
        $wam->data = $data;

        // Si se proporciona un timestamp, se establece la fecha de creación y actualización
        if (!is_null($timestamp)) {
            $wam->created_at = Carbon::createFromTimestamp($timestamp)->toDateTimeString();
            $wam->updated_at = Carbon::createFromTimestamp($timestamp)->toDateTimeString();
        }

        // Guarda el mensaje en la base de datos
        $wam->save();

        // Dispara el evento Webhook después de guardar el mensaje
        Webhook::dispatch($wam, false);

        // Retorna el mensaje guardado
        return $wam;
    }
}
