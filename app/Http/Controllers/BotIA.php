<?php

namespace App\Http\Controllers;

use App\Models\Thread;
use Illuminate\Http\Request;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Threads\Runs\ThreadRunResponse;

class BotIA extends Controller
{
    public ?string $question = null;
    public ?string $answer = null;
    public ?string $error = null;


    // Método para manejar preguntas
    public function ask($question, $waId)
    {
        $this->question = $question;

        // Buscar si ya existe un hilo para este usuario
        $thread = Thread::where('wa_id', $waId)->first();

        if ($thread) {
            // Si existe un hilo, usar el hilo existente
            $threadRun = $this->continueThread($thread->thread_id);
        } else {
            // Si no existe un hilo, crear uno nuevo
            $threadRun = $this->createAndRunThread();

            // Guardar el nuevo hilo en la base de datos
            Thread::create([
                'wa_id' => $waId,
                'thread_id' => $threadRun->threadId,
            ]);
        }

        // Cargar la respuesta del hilo
        $this->loadAnswer($threadRun);

        return $this->answer;
    }

    // En tu controlador BotIA, asegúrate de definir este método para manejar hilos existentes
    public function askUsingThread($threadId, $question)
    {
        $this->question = $question;

        // Continuar el hilo existente
        $threadRun = $this->continueThread($threadId);

        // Cargar y devolver la respuesta
        $this->loadAnswer($threadRun);

        return $this->answer;
    }

    // Método para crear y ejecutar un nuevo hilo
    private function createAndRunThread(): ThreadRunResponse
    {
        return OpenAI::threads()->createAndRun([
            'assistant_id' => env('OPENAI_ASSISTANT_ID'),
            'thread' => [
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $this->question,
                    ],
                ],
            ],
        ]);
    }

    // Método para continuar un hilo existente
    private function continueThread($threadId): ThreadRunResponse
    {
        // Primero, envía un mensaje al hilo existente
        OpenAI::threads()->messages()->create(
            $threadId, // Pasar el threadId como string
            [
                'role' => 'user',
                'content' => $this->question,
            ]
        );

        // Luego, crea un run para continuar con el hilo
        return OpenAI::threads()->runs()->create(
            $threadId, // Pasar el threadId como string
            [
                'assistant_id' => env('OPENAI_ASSISTANT_ID'),
            ]
        );
    }


    // Método para cargar la respuesta desde el hilo
    private function loadAnswer(ThreadRunResponse $threadRun)
    {
        while (in_array($threadRun->status, ['queued', 'in_progress'])) {
            $threadRun = OpenAI::threads()->runs()->retrieve(
                threadId: $threadRun->threadId,
                runId: $threadRun->id,
            );
        }

        if ($threadRun->status !== 'completed') {
            $this->error = 'Request failed, please try again';
            return;
        }

        $messageList = OpenAI::threads()->messages()->list(
            threadId: $threadRun->threadId,
        );

        // Asigna la respuesta obtenida del mensaje
        $this->answer = $messageList->data[0]->content[0]->text->value ?? 'No answer received';
    }
}
