<?php

namespace App\Jobs;

use App\Libraries\Whatsapp;
use App\Models\Message;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $payload;
    public $body;
    public $messageData;
    public $tokenApp;
    public $phone_id;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($tokenApp, $phone_id, $payload, $body, $messageData = [])
    {
        $this->payload = $payload;
        $this->body = $body;
        $this->messageData = $messageData;
        $this->tokenApp = $tokenApp;
        $this->phone_id = $phone_id;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        try {
            $wp = new Whatsapp();
            $request = $wp->genericPayload($this->payload, $this->tokenApp, $this->phone_id);

            $wam = new Message();
            $wam->body = $this->body;
            $wam->outgoing = true;
            $wam->type = 'template';
            $wam->wa_id = $request["contacts"][0]["wa_id"];
            $wam->wam_id = $request["messages"][0]["id"];
            $wam->phone_id = $this->phone_id;
            $wam->status = 'sent';
            $wam->caption = '';
            $wam->data = serialize($this->messageData);
            $wam->save();
        } catch (Exception $e) {
            // Captura la excepción y registra un mensaje de error detallado
        $errorMessage = 'Error handling SendMessage job: ' . $e->getMessage();

        // Agrega información adicional al mensaje de error
        $errorMessage .= "\nPayload: " . json_encode($this->payload);
        $errorMessage .= "\nBody: " . $this->body;
        $errorMessage .= "\nMessage Data: " . json_encode($this->messageData);

        Log::error($errorMessage);

        }
    }
}
