<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;


class SendSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $user, $message;

    /**
     * Create a new job instance.
     * @param  $user ,$message
     *
     * @return void
     */
    public function __construct($user, $message)
    {
        $this->user = $user;
        $this->message = $message;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        Log::info("sms job start to run");
        try{


            $rcpt_nm = $this->user->mobile;


            $curl = curl_init();

            curl_setopt_array($curl, array(
              CURLOPT_URL => 'http://rest.ippanel.com/v1/messages',
              CURLOPT_RETURNTRANSFER => true,
              CURLOPT_ENCODING => '',
              CURLOPT_MAXREDIRS => 10,
              CURLOPT_TIMEOUT => 0,
              CURLOPT_FOLLOWLOCATION => true,
              CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
              CURLOPT_CUSTOMREQUEST => 'POST',
              CURLOPT_POSTFIELDS =>'{
                "originator": "'.env("SMS_PANNEL_FROM").'",
                "recipients": [
                    "'.$rcpt_nm.'"
                ],
                "message": "'.$this->message.'"
              }',
              CURLOPT_HTTPHEADER =>array(
                'Authorization: AccessKey 6rEPrwDLs0ACjS0IPQvW9HNdLLx1W2yR534iOVTolRc=',
                'Content-Type: application/json'
              ),

            ));
            $response = curl_exec($curl);
            Log::info($response);
            curl_close($curl);

        }catch (\Exception $ex){
            Log::warning(json_encode($ex));
        }

    }
}

