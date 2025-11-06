<?php

namespace App\Traits\Holo;

use Illuminate\Support\Facades\Log;

trait Holo{


    public function getAccounts($user):array {


        //$config = json_decode($user->config);
        $config = $user->config;

        $curl = curl_init();
        $url = env('SERVICE_URL');
        $token = $user->dashboardToken;

        curl_setopt_array($curl, array(
          CURLOPT_URL => $url.'/GetAllAccount',
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => '',
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_TIMEOUT => 0,
          CURLOPT_FOLLOWLOCATION => true,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
          CURLOPT_POSTFIELDS =>json_encode($config), // convert data array to JSON
          CURLOPT_CUSTOMREQUEST => 'POST',
          CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Authorization: Bearer '.$token
          ),
        ));

        $response = curl_exec($curl);
        $responseData = json_decode($response, true); // Decode the JSON response

        $responseCode = curl_getinfo($curl, CURLINFO_HTTP_CODE); // Get the response code
        curl_close($curl);
        $accounts=[];
        if ($responseCode == 200) {
            $response = $responseData['response'];
            //dd($response);
            foreach($response as $key=>$value){
                    $accounts[] =(object)array("sarfasl_Code"=>$value["sarfasl_Code"],"sarfasl_Name"=>$value["sarfasl_Name"]);
            }
            return $accounts;
        }

        return $accounts;

    }

    public function testHoloProducts($user):array {


        //$config = json_decode($user->config);
        $config = $user->config;

        $curl = curl_init();
        $url = env('SERVICE_URL');
        $token = $user->dashboardToken;

        curl_setopt_array($curl, array(
          CURLOPT_URL => $url.'/getAllHolooProducts',
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => '',
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_TIMEOUT => 0,
          CURLOPT_FOLLOWLOCATION => true,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
          CURLOPT_CUSTOMREQUEST => 'GET',
          CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Authorization: Bearer '.$token
          ),
        ));

        $response = curl_exec($curl);
        $responseData = json_decode($response, true); // Decode the JSON response

        $responseCode = curl_getinfo($curl, CURLINFO_HTTP_CODE); // Get the response code
        curl_close($curl);
        $accounts=[];
        $count=0;
        if ($responseCode == 200) {
            $response = $responseData['response'];
            $responseData = json_decode($response, true); // Decode the JSON response
            //log::info($responseData);
            if($responseData!=null and $responseData["data"]!=null and $responseData["data"]['product']!=null){
              $responseData =$responseData["data"]['product'];
              $count=count($responseData);
            }
            else
            $count=0;
        }

        return ["count"=>$count,"response"=>$responseCode];

    }

    public function testPage1HolooProducts($user):array {


        //$config = json_decode($user->config);
        $config = $user->config;

        $curl = curl_init();
        $url = env('SERVICE_URL');
        $token = $user->dashboardToken;

        curl_setopt_array($curl, array(
          CURLOPT_URL => $url.'/getPage1HolooProducts',
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => '',
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_TIMEOUT => 0,
          CURLOPT_FOLLOWLOCATION => true,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
          CURLOPT_CUSTOMREQUEST => 'GET',
          CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Authorization: Bearer '.$token
          ),
        ));

        $response = curl_exec($curl);
        $responseData = json_decode($response, true); // Decode the JSON response

        $responseCode = curl_getinfo($curl, CURLINFO_HTTP_CODE); // Get the response code
        curl_close($curl);

        $count=0;
        if ($responseCode == 200) {
            $response = $responseData['response'];
            $responseData = json_decode($response, true); // Decode the JSON response
            //log::info($responseData);
            if($responseData!=null and $responseData["data"]!=null and $responseData["data"]['product']!=null){
              $responseData =$responseData["data"]['product'];
              $count=count($responseData);
            }
            else
            $count=0;
        }

        return ["count"=>$count,"response"=>$responseCode];

    }

    public function testProductsPagingCount($user):array {


        //$config = json_decode($user->config);
        $config = $user->config;

        $curl = curl_init();
        $url = env('SERVICE_URL');
        $token = $user->dashboardToken;

        curl_setopt_array($curl, array(
          CURLOPT_URL => $url.'/getProductsPagingCount',
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => '',
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_TIMEOUT => 0,
          CURLOPT_FOLLOWLOCATION => true,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
          CURLOPT_CUSTOMREQUEST => 'GET',
          CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Authorization: Bearer '.$token
          ),
        ));

        $response = curl_exec($curl);
        $responseCode = curl_getinfo($curl, CURLINFO_HTTP_CODE); // Get the response code
        curl_close($curl);

        $count=0;
        if ($responseCode == 200) {
            $count=$response;
        }


        return ["count"=>$count,"response"=>$responseCode];

    }

    public function testHoloCategorys($user):array {


        //$config = json_decode($user->config);
        $config = $user->config;

        $curl = curl_init();
        $url = env('SERVICE_URL');
        $token = $user->dashboardToken;

        curl_setopt_array($curl, array(
          CURLOPT_URL => $url.'/getProductCategory',
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => '',
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_TIMEOUT => 0,
          CURLOPT_FOLLOWLOCATION => true,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
          CURLOPT_POSTFIELDS =>json_encode($config), // convert data array to JSON
          CURLOPT_CUSTOMREQUEST => 'POST',
          CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Authorization: Bearer '.$token
          ),
        ));

        $response = curl_exec($curl);
        $responseData = json_decode($response, true); // Decode the JSON response

        $responseCode = curl_getinfo($curl, CURLINFO_HTTP_CODE); // Get the response code
        curl_close($curl);

        $count=0;
        if ($responseCode == 200) {
            //log::info($responseData);
            $response = $responseData['response'];
            $responseData =$response["result"];
            if($responseData!=null)
            $count=count($responseData);
            else
            $count=0;
        }

        return ["count"=>$count,"response"=>$responseCode];

    }

    public function testHoloCustomerAccount($user):array {


        //$config = json_decode($user->config);
        $config = $user->config;

        $curl = curl_init();
        $url = env('SERVICE_URL');
        $token = $user->dashboardToken;

        curl_setopt_array($curl, array(
          CURLOPT_URL => $url.'/GetAllCustomerAccount',
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => '',
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_TIMEOUT => 0,
          CURLOPT_FOLLOWLOCATION => true,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
          CURLOPT_POSTFIELDS =>json_encode($config), // convert data array to JSON
          CURLOPT_CUSTOMREQUEST => 'POST',
          CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Authorization: Bearer '.$token
          ),
        ));

        $response = curl_exec($curl);
        $responseData = json_decode($response, true); // Decode the JSON response

        $responseCode = curl_getinfo($curl, CURLINFO_HTTP_CODE); // Get the response code
        curl_close($curl);
        $accounts=[];
        if ($responseCode == 200) {
            $response = $responseData['response'];
            //dd($response);
            foreach($response as $key=>$value){
                    $accounts[] =(object)array("sarfasl_Code"=>$value["sarfasl_Code"],"sarfasl_Name"=>$value["sarfasl_Name"]);
            }

        }

        return ["count"=>count($accounts),"response"=>$responseCode];

    }

    public function testHoloAccounts($user):array {


        //$config = json_decode($user->config);
        $config = $user->config;

        $curl = curl_init();
        $url = env('SERVICE_URL');
        $token = $user->dashboardToken;

        curl_setopt_array($curl, array(
          CURLOPT_URL => $url.'/GetAllAccount',
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => '',
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_TIMEOUT => 0,
          CURLOPT_FOLLOWLOCATION => true,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
          CURLOPT_POSTFIELDS =>json_encode($config), // convert data array to JSON
          CURLOPT_CUSTOMREQUEST => 'POST',
          CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Authorization: Bearer '.$token
          ),
        ));

        $response = curl_exec($curl);
        $responseData = json_decode($response, true); // Decode the JSON response

        $responseCode = curl_getinfo($curl, CURLINFO_HTTP_CODE); // Get the response code
        curl_close($curl);
        $accounts=[];
        if ($responseCode == 200) {
            $response = $responseData['response'];
            //dd($response);
            foreach($response as $key=>$value){
                    $accounts[] =(object)array("sarfasl_Code"=>$value["sarfasl_Code"],"sarfasl_Name"=>$value["sarfasl_Name"]);
            }

        }

        return ["count"=>count($accounts),"response"=>$responseCode];

    }

    public function getGroupProducts($user,$category_key) {


        //$config = json_decode($user->config);
        $config = $user->config;

        $curl = curl_init();
        $url = env('SERVICE_URL');
        $token = $user->dashboardToken;

        curl_setopt_array($curl, array(
          CURLOPT_URL => $url.'/getAllHolooProductsWithCategory/'.$category_key,
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => '',
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_TIMEOUT => 0,
          CURLOPT_FOLLOWLOCATION => true,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
          CURLOPT_POSTFIELDS =>json_encode($config), // convert data array to JSON
          CURLOPT_CUSTOMREQUEST => 'POST',
          CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Authorization: Bearer '.$token
          ),
        ));

        $response = curl_exec($curl);
        $responseData = json_decode($response, true); // Decode the JSON response

        $responseCode = curl_getinfo($curl, CURLINFO_HTTP_CODE); // Get the response code
        curl_close($curl);
        $products=[];
        if ($responseCode == 200) {
            $response = $responseData["response"];
            #{id: 123 , name: "کفش",amount: 20, price: "24000", status: 0}

            foreach($response as $product){
                $products[]=(object)["id"=>$product["a_Code"],"name"=>$product["name"],"amount"=>$product["few"],"price"=>$product["sellPrice"],"poshak"=>$product["poshak"],"status"=>0];
            }


        }

        return $products;

    }

    public function addProductsWebhook($user,$products){
        $url = env('SERVICE_URL');

        $hookMsgValue=[];
        $hookMsgType=[];
        foreach($products as $product){
            $hookMsgValue[]=$product;
            $hookMsgType[] = 0;
        }
        // Convert the arrays to strings
        $msgValue = implode (",", $hookMsgValue);
        $msgType = implode (",", $hookMsgType);
        $array = [
            "Dbname" => $user->holooCustomerID."_holoo1",
            "Table" => "Article",
            "MsgType" => "1",
            "MsgValue" => $msgValue,
            "MsgError" => null,
            "Message" => $msgType,
        ];
        $json = json_encode ($array);
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $url.'/webhook',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            // Use the strings in the post fields
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json'
            ),
        ));

        $response = curl_exec($curl);
        //log::info($response);

        return true;
    }

    public function addProductsPoshakWebhook($user,$products){
        $url = env('SERVICE_URL');

        $hookMsgValue=[];
        $hookMsgType=[];
        foreach($products as $product){
            $hookMsgValue[]=$product;
            $hookMsgType[] = 2;
        }
        // Convert the arrays to strings
        $msgValue = implode (",", $hookMsgValue);
        $msgType = implode (",", $hookMsgType);
        $array = [
            "Dbname" => $user->holooCustomerID."_holoo1",
            "Table" => "Poshak",
            "MsgType" => "0",
            "MsgValue" => $msgValue,
            "MsgError" => null,
            "Message" => $msgType,
        ];
        $json = json_encode ($array);
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $url.'/webhook',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            // Use the strings in the post fields
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json'
            ),
        ));

        $response = curl_exec($curl);
        //log::info($response);

        return true;
    }

    public function addInvoicesWebhook($user,$invoices){
        $url = env('SERVICE_URL');
        $token = $user->dashboardToken;

        foreach($invoices as $invoice){
            $json = json_encode ($invoice->invoice);
            $curl = curl_init();

            curl_setopt_array($curl, array(
                CURLOPT_URL => $url.'/wcInvoicePayed',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                // Use the strings in the post fields
                CURLOPT_POSTFIELDS => $json,
                CURLOPT_HTTPHEADER => array(
                    'Content-Type: application/json',
                    'Authorization: Bearer '.$token
                ),
            ));

            $response = curl_exec($curl);
            //log::info($response);

        }


        return true;
    }

    public function getJobInQueue($user):array {


        //$config = json_decode($user->config);

        $curl = curl_init();
        $url = env('SERVICE_URL');
        $token = $user->dashboardToken;

        curl_setopt_array($curl, array(
          CURLOPT_URL => $url.'/getJobInQueue',
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => '',
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_TIMEOUT => 0,
          CURLOPT_FOLLOWLOCATION => true,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,

          CURLOPT_CUSTOMREQUEST => 'GET',
          CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Authorization: Bearer '.$token
          ),
        ));

        $response = curl_exec($curl);
        $responseData = json_decode($response, true); // Decode the JSON response

        $responseCode = curl_getinfo($curl, CURLINFO_HTTP_CODE); // Get the response code
        curl_close($curl);
        $queues=[];
        if ($responseCode == 200) {
            $response = $responseData['response'];
            //dd($response);
            $queues[] =(object)array("count"=>$response["count"],"name"=>$response["name"]);

            return $queues;
        }

        return $queues;

    }

    public function getExcelProducts($user) {


        //$config = json_decode($user->config);

        $curl = curl_init();
        $url = env('SERVICE_URL');
        $token = $user->dashboardToken;

        curl_setopt_array($curl, array(
          CURLOPT_URL => $url.'/wcGetExcelProducts',
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => '',
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_TIMEOUT => 0,
          CURLOPT_FOLLOWLOCATION => true,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,

          CURLOPT_CUSTOMREQUEST => 'GET',
          CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Authorization: Bearer '.$token
          ),
        ));

        $response = curl_exec($curl);
        $responseData = json_decode($response, true); // Decode the JSON response

        $responseCode = curl_getinfo($curl, CURLINFO_HTTP_CODE); // Get the response code
        curl_close($curl);

        if ($responseCode == 200) {
            $response = $responseData['response']['result']['url'];
            return $response;
        }


    }
    public function getExcelProducts2($user) {


        //$config = json_decode($user->config);

        $curl = curl_init();
        $url = env('SERVICE_URL');
        $token = $user->dashboardToken;

        curl_setopt_array($curl, array(
          CURLOPT_URL => 'https://laravel.clawar-services.org/liveWcGetExcelProducts2/'.$user->id,
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => '',
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_TIMEOUT => 0,
          CURLOPT_FOLLOWLOCATION => true,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,

          CURLOPT_CUSTOMREQUEST => 'GET',
          CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Authorization: Bearer '.$token
          ),
        ));

        $response = curl_exec($curl);
        return $response;


    }
    public function getExcelProducts3($user) {


        //$config = json_decode($user->config);

        $curl = curl_init();
        $url = env('SERVICE_URL');
        $token = $user->dashboardToken;

        curl_setopt_array($curl, array(
          CURLOPT_URL => 'https://laravel.clawar-services.org/liveWcGetExcelProducts3/'.$user->id,
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => '',
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_TIMEOUT => 0,
          CURLOPT_FOLLOWLOCATION => true,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,

          CURLOPT_CUSTOMREQUEST => 'GET',
          CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Authorization: Bearer '.$token
          ),
        ));

        $response = curl_exec($curl);
        return $response;


    }
}
