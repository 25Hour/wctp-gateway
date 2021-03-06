<?php

namespace App\Jobs;

use Exception;
use App\Carrier;
use App\Message;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use GuzzleHttp\Client as Guzzle;
use Illuminate\Queue\SerializesModels;
use Twilio\Rest\Client as TwilioClient;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class SyncOutboundStatus implements ShouldQueue
{

    public $tries = 3;

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $deleteWhenMissingModels = true;

    public $message, $carrier;

    public function __construct( Message $message )
    {
        $this->message = $message;
    }

    public function handle()
    {
        $this->carrier = Carrier::where( 'enabled', 1 )->where( 'id', $this->message->carrier_id )->first();

        if( is_null( $this->carrier ) )
        {
            LogEvent::dispatch(
                "Failure synchronizing message status",
                get_class( $this ), 'error', json_encode("No enabled carrier for message"), null
            );
            return $this->markSent();
        }

        if( $this->carrier->api == 'twilio' )
        {
            //use twilio api to check status of message->carrier_uniquie_id
            try{
                $client = new TwilioClient(
                    $this->carrier->twilio_account_sid,
                    decrypt( $this->carrier->twilio_auth_token )
                );
            }
            catch( Exception $e ){
                LogEvent::dispatch(
                    "Failure synchronizing message status",
                    get_class( $this ), 'error', json_encode($e->getMessage()), null
                );
                return false;
            }

            try{
                $carrier_message = $client->messages( $this->message->carrier_message_uid )->fetch();
                $this->message->status = $carrier_message->status;
                switch ($carrier_message->status) {
                    case "sent":
                    case "DELIVRD":
                    case "delivered":
                        $this->message->delivered_at = Carbon::now();
                        break;
                    case "REJECTD":
                    case "EXPIRED":
                    case "DELETED":
                    case "UNKNOWN":
                    case "failed":
                    case "undelivered":
                    case "UNDELIV":
                    default:
                        $this->message->failed_at = Carbon::now();
                        break;
                }
                $this->message->save();
            }
            catch( Exception $e ){
                LogEvent::dispatch(
                    "Failure synchronizing message status",
                    get_class( $this ), 'error', json_encode([$e->getMessage(), $this->from]), null
                );
                return false;
            }
        }
        elseif( $this->carrier->api == 'thinq')
        {
            /*
             * https://api.thinq.com/account/{{account_id}}/product/origination/sms/{{message_id}}
             */
            try{
                $thinq = new Guzzle([
                    'timeout' => 10.0,
                    'base_uri' => 'https://api.thinq.com',
                    'auth' => [ $this->carrier->thinq_api_username, decrypt($this->carrier->thinq_api_token)],
                ]);
            }
            catch( Exception $e ){
                LogEvent::dispatch(
                    "Failed decrypting carrier api token",
                    get_class( $this ), 'error', json_encode($this->carrier->toArray()), null
                );
                return $this->markSent();
            }

            try{
                $result = $thinq->get("account/{$this->carrier->thinq_account_id}/product/origination/sms/{$this->message->carrier_message_uid}");
            }
            catch( Exception $e )
            {
                LogEvent::dispatch(
                    "Failure synchronizing message status",
                    get_class( $this ), 'error', json_encode($e->getMessage()), null
                );

                return $this->markSent();
            }

            if( $result->getStatusCode() != 200 )
            {
                LogEvent::dispatch(
                    "Failure synchronizing message status",
                    get_class( $this ), 'error', json_encode($result->getReasonPhrase()), null
                );
                return $this->markSent();
            }
            $body = $result->getBody();
            $json = $body->getContents();
            $arr = json_decode( $json, true );
            if( ! isset( $arr['delivery_notifications']))
            {
                LogEvent::dispatch(
                    "Failure synchronizing message status",
                    get_class( $this ), 'error', json_encode([$arr, $arr['delivery_notifications']]), null
                );
                return $this->markSent();
            }

            foreach( $arr['delivery_notifications'] as $dn )
            {
                switch( $dn['send_status'] ) {
                    case "sent":
                    case "DELIVRD":
                    case "delivered":
                        $this->message->status = $dn['send_status'];
                        $this->message->delivered_at = Carbon::now();
                        break;
                    case "REJECTD":
                    case "EXPIRED":
                    case "DELETED":
                    case "UNKNOWN":
                    case "failed":
                    case "undelivered":
                    case "UNDELIV":
                        $this->message->status = $dn['send_status'];
                        $this->message->failed_at = Carbon::now();
                        break;
                    default:
                        break;
                }
            }

            try{
                $this->message->save();
            }
            catch( Exception $e ){
                LogEvent::dispatch(
                    "Failure updating message status",
                    get_class( $this ), 'error', json_encode($e->getMessage()), null
                );
                return false;
            }
        }
        else
        {
            //unsupported carrier
            return false;
        }

        return true;
    }

    private function markSent()
    {
        try{
            $this->message->status = 'delivered';
            $this->message->failed_at = Carbon::now();
            $this->message->save();
        }
        catch( Exception $e ){
            LogEvent::dispatch(
                "Failure updating message status",
                get_class( $this ), 'error', json_encode($e->getMessage()), null
            );
            return false;
        }

        return true;
    }
}
