<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class B2Sell_SEM_Campaigns {

    public function __construct() {
        add_action( 'wp_ajax_b2sell_googleads_test', array( $this, 'ajax_test_connection' ) );
        add_action( 'wp_ajax_b2sell_sem_gpt', array( $this, 'ajax_gpt_suggestions' ) );
    }

    public function render_admin_page() {
        $tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'googleads';
        echo '<div class="wrap">';
        echo '<h1>Campañas SEM</h1>';

        echo '<h2 class="nav-tab-wrapper">';
        echo '<a href="?page=b2sell-seo-campanas&tab=googleads" class="nav-tab ' . ( 'googleads' === $tab ? 'nav-tab-active' : '' ) . '">Configuración Google Ads</a>';
        echo '<a href="?page=b2sell-seo-campanas&tab=campanas" class="nav-tab ' . ( 'campanas' === $tab ? 'nav-tab-active' : '' ) . '">Campañas activas</a>';
        echo '<a href="?page=b2sell-seo-campanas&tab=gpt" class="nav-tab ' . ( 'gpt' === $tab ? 'nav-tab-active' : '' ) . '">Sugerencias con GPT</a>';
        echo '</h2>';

        switch ( $tab ) {
            case 'campanas':
                $this->render_campaigns_tab();
                break;
            case 'gpt':
                $this->render_gpt_tab();
                break;
            case 'googleads':
            default:
                $this->render_googleads_tab();
                break;
        }

        echo '<hr />';
        echo '</div>';
    }

    private function render_googleads_tab() {
        $customer_id     = get_option( 'b2sell_googleads_customer_id', '' );
        $developer_token = get_option( 'b2sell_googleads_developer_token', '' );
        $refresh_token   = get_option( 'b2sell_googleads_refresh_token', '' );
        $mcc_id          = get_option( 'b2sell_googleads_mcc_id', '' );

        if ( isset( $_POST['b2sell_googleads_customer_id'] ) ) {
            check_admin_referer( 'b2sell_save_googleads' );
            $customer_id     = sanitize_text_field( $_POST['b2sell_googleads_customer_id'] );
            $developer_token = sanitize_text_field( $_POST['b2sell_googleads_developer_token'] );
            $refresh_token   = sanitize_text_field( $_POST['b2sell_googleads_refresh_token'] );
            $mcc_id          = sanitize_text_field( $_POST['b2sell_googleads_mcc_id'] );
            update_option( 'b2sell_googleads_customer_id', $customer_id );
            update_option( 'b2sell_googleads_developer_token', $developer_token );
            update_option( 'b2sell_googleads_refresh_token', $refresh_token );
            update_option( 'b2sell_googleads_mcc_id', $mcc_id );
            echo '<div class="updated"><p>Credenciales guardadas.</p></div>';
        }

        $nonce = wp_create_nonce( 'b2sell_googleads_test' );
        echo '<form method="post">';
        wp_nonce_field( 'b2sell_save_googleads' );
        echo '<table class="form-table">';
        echo '<tr><th scope="row"><label for="b2sell_googleads_customer_id">ID de cliente de Google Ads</label></th><td><input type="text" id="b2sell_googleads_customer_id" name="b2sell_googleads_customer_id" value="' . esc_attr( $customer_id ) . '" class="regular-text" /></td></tr>';
        echo '<tr><th scope="row"><label for="b2sell_googleads_developer_token">ID de desarrollador</label></th><td><input type="text" id="b2sell_googleads_developer_token" name="b2sell_googleads_developer_token" value="' . esc_attr( $developer_token ) . '" class="regular-text" /></td></tr>';
        echo '<tr><th scope="row"><label for="b2sell_googleads_refresh_token">Token de refresco (OAuth2)</label></th><td><input type="text" id="b2sell_googleads_refresh_token" name="b2sell_googleads_refresh_token" value="' . esc_attr( $refresh_token ) . '" class="regular-text" /></td></tr>';
        echo '<tr><th scope="row"><label for="b2sell_googleads_mcc_id">ID de cuenta MCC (opcional)</label></th><td><input type="text" id="b2sell_googleads_mcc_id" name="b2sell_googleads_mcc_id" value="' . esc_attr( $mcc_id ) . '" class="regular-text" /></td></tr>';
        echo '</table>';
        submit_button( 'Guardar credenciales' );
        echo ' <button type="button" class="button" id="b2sell_googleads_test_btn">Probar conexión</button>';
        echo '</form>';
        echo '<div id="b2sell_googleads_test_result"></div>';
        echo '<script>jQuery(function($){$("#b2sell_googleads_test_btn").on("click",function(){var r=$("#b2sell_googleads_test_result");r.html("Probando...");$.post(ajaxurl,{action:"b2sell_googleads_test",_wpnonce:"' . esc_js( $nonce ) . '"},function(res){if(res.success){r.html("<div class=\"updated\"><p>Conexión exitosa</p></div>");}else{r.html("<div class=\"error\"><p>"+res.data+"</p></div>");}});});});</script>';
    }

    private function render_campaigns_tab() {
        $status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'all';
        echo '<h3>Lectura de campañas activas</h3>';
        echo '<form method="get" style="margin-bottom:10px;">';
        echo '<input type="hidden" name="page" value="b2sell-seo-campanas" />';
        echo '<input type="hidden" name="tab" value="campanas" />';
        echo '<select name="status"><option value="all"' . selected( $status, 'all', false ) . '>Todas</option><option value="active"' . selected( $status, 'active', false ) . '>Activas</option><option value="paused"' . selected( $status, 'paused', false ) . '>Pausadas</option></select> ';
        submit_button( 'Filtrar', 'secondary', '', false );
        echo '</form>';

        if ( ! class_exists( '\\Google\\Ads\\GoogleAds\\Lib\\V14\\GoogleAdsClientBuilder' ) ) {
            echo '<p>SDK de Google Ads no disponible.</p>';
            return;
        }

        $customer_id     = get_option( 'b2sell_googleads_customer_id', '' );
        $developer_token = get_option( 'b2sell_googleads_developer_token', '' );
        $refresh_token   = get_option( 'b2sell_googleads_refresh_token', '' );
        $mcc_id          = get_option( 'b2sell_googleads_mcc_id', '' );

        if ( ! $customer_id || ! $developer_token || ! $refresh_token ) {
            echo '<p>Credenciales de Google Ads incompletas.</p>';
            return;
        }

        try {
            $o_auth2 = new \Google\Auth\OAuth2(
                array(
                    'clientId'     => '',
                    'clientSecret' => '',
                    'refresh_token' => $refresh_token,
                )
            );
            $builder = ( new \Google\Ads\GoogleAds\Lib\V14\GoogleAdsClientBuilder() )
                ->withDeveloperToken( $developer_token )
                ->withOAuth2Credential( $o_auth2 );
            if ( $mcc_id ) {
                $builder->withLoginCustomerId( $mcc_id );
            }
            $google_ads_client   = $builder->build();
            $google_ads_service  = $google_ads_client->getGoogleAdsServiceClient();
            $query               = 'SELECT campaign.name, campaign.status, campaign_budget.amount_micros, metrics.impressions, metrics.clicks, metrics.ctr, metrics.cost_micros FROM campaign WHERE segments.date DURING LAST_30_DAYS';
            if ( 'active' === $status ) {
                $query .= " AND campaign.status = 'ENABLED'";
            } elseif ( 'paused' === $status ) {
                $query .= " AND campaign.status = 'PAUSED'";
            }
            $response = $google_ads_service->search( $customer_id, $query );
            echo '<table class="widefat fixed"><thead><tr><th>Nombre de campaña</th><th>Estado</th><th>Presupuesto diario</th><th>Impresiones</th><th>Clics</th><th>CTR</th><th>Costo</th></tr></thead><tbody>';
            foreach ( $response->iterateAllElements() as $row ) {
                $campaign = $row->getCampaign();
                $budget   = $row->getCampaignBudget();
                $metrics  = $row->getMetrics();
                echo '<tr>';
                echo '<td>' . esc_html( $campaign->getName() ) . '</td>';
                echo '<td>' . esc_html( $campaign->getStatus() ) . '</td>';
                echo '<td>' . esc_html( number_format( $budget->getAmountMicros() / 1000000, 2 ) ) . '</td>';
                echo '<td>' . esc_html( $metrics->getImpressions() ) . '</td>';
                echo '<td>' . esc_html( $metrics->getClicks() ) . '</td>';
                echo '<td>' . esc_html( number_format( $metrics->getCtr(), 2 ) ) . '%</td>';
                echo '<td>' . esc_html( number_format( $metrics->getCostMicros() / 1000000, 2 ) ) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        } catch ( \Exception $e ) {
            echo '<div class="error"><p>' . esc_html( $e->getMessage() ) . '</p></div>';
        }
    }

    private function render_gpt_tab() {
        echo '<h3>Sugerencias con GPT</h3>';
        if ( ! function_exists( 'wc_get_products' ) ) {
            echo '<p>WooCommerce no está activo.</p>';
            return;
        }
        $products = wc_get_products( array( 'limit' => -1, 'status' => 'publish' ) );
        echo '<p>Selecciona un producto para generar ideas de anuncios.</p>';
        echo '<select id="b2sell_sem_product"><option value="">Selecciona un producto</option>';
        foreach ( $products as $product ) {
            echo '<option value="' . esc_attr( $product->get_id() ) . '">' . esc_html( $product->get_name() . ' - ' . $product->get_price() ) . '</option>';
        }
        echo '</select>';
        echo '<div id="b2sell_sem_gpt_results" style="display:none;border:1px solid #ccc;padding:10px;margin-top:20px;"></div>';
        $nonce = wp_create_nonce( 'b2sell_sem_gpt' );
        echo '<script>var b2sellLastSuggestions=null;jQuery(function($){$("#b2sell_sem_product").on("change",function(){var pid=$(this).val();if(!pid){return;}$("#b2sell_sem_gpt_results").show().html("Generando...");$.post(ajaxurl,{action:"b2sell_sem_gpt",product_id:pid,_wpnonce:"' . esc_js( $nonce ) . '"},function(res){if(res.success){b2sellLastSuggestions=res.data;var html="<h2>Sugerencias de anuncios - B2SELL</h2>";html+="<h3>Titulares</h3><ul>";res.data.headlines.forEach(function(h){html+="<li>"+h+"</li>";});html+="</ul><h3>Descripciones</h3><ul>";res.data.descriptions.forEach(function(d){html+="<li>"+d+"</li>";});html+="</ul><h3>Palabras clave</h3><ul>";res.data.keywords.forEach(function(k){html+="<li>"+k+"</li>";});html+="</ul><button class=\"button\" id=\"b2sell_sem_copy\">Copiar</button> <button class=\"button\" id=\"b2sell_sem_csv\">Exportar CSV</button>";$("#b2sell_sem_gpt_results").html(html);}else{var msg=res.data && res.data.message?res.data.message:res.data;$("#b2sell_sem_gpt_results").html("<div class=\'b2sell-red\' style=\'padding:10px;\'>"+msg+"</div>");}});});$(document).on("click","#b2sell_sem_copy",function(){var text=$("#b2sell_sem_gpt_results").text();navigator.clipboard.writeText(text);});$(document).on("click","#b2sell_sem_csv",function(){if(!b2sellLastSuggestions)return;var csv="Titulares\n"+b2sellLastSuggestions.headlines.join("\n")+"\n\nDescripciones\n"+b2sellLastSuggestions.descriptions.join("\n")+"\n\nPalabras clave\n"+b2sellLastSuggestions.keywords.join(", ");var blob=new Blob([csv],{type:"text/csv"});var a=document.createElement("a");a.href=URL.createObjectURL(blob);a.download="sugerencias.csv";a.click();});});</script>';
    }

    public function ajax_test_connection() {
        check_ajax_referer( 'b2sell_googleads_test' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permisos insuficientes' );
        }
        $customer_id     = get_option( 'b2sell_googleads_customer_id', '' );
        $developer_token = get_option( 'b2sell_googleads_developer_token', '' );
        $refresh_token   = get_option( 'b2sell_googleads_refresh_token', '' );
        if ( ! $customer_id || ! $developer_token || ! $refresh_token ) {
            wp_send_json_error( 'Credenciales incompletas' );
        }
        if ( ! class_exists( '\\Google\\Ads\\GoogleAds\\Lib\\V14\\GoogleAdsClientBuilder' ) ) {
            wp_send_json_error( 'SDK de Google Ads no disponible' );
        }
        try {
            $o_auth2 = new \Google\Auth\OAuth2(
                array(
                    'clientId'     => '',
                    'clientSecret' => '',
                    'refresh_token' => $refresh_token,
                )
            );
            $client = ( new \Google\Ads\GoogleAds\Lib\V14\GoogleAdsClientBuilder() )
                ->withDeveloperToken( $developer_token )
                ->withOAuth2Credential( $o_auth2 )
                ->build();
            $service = $client->getGoogleAdsServiceClient();
            $service->search( $customer_id, 'SELECT customer.id FROM customer LIMIT 1' );
            wp_send_json_success();
        } catch ( \Exception $e ) {
            wp_send_json_error( $e->getMessage() );
        }
    }

    public function ajax_gpt_suggestions() {
        check_ajax_referer( 'b2sell_sem_gpt' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permisos insuficientes' ) );
        }
        if ( ! function_exists( 'wc_get_product' ) ) {
            wp_send_json_error( array( 'message' => 'WooCommerce no disponible' ) );
        }
        $product_id = intval( $_POST['product_id'] ?? 0 );
        $product    = wc_get_product( $product_id );
        if ( ! $product ) {
            wp_send_json_error( array( 'message' => 'Producto inválido' ) );
        }
        $api_key = get_option( 'b2sell_openai_api_key', '' );
        if ( ! $api_key ) {
            wp_send_json_error( array( 'message' => 'API Key no configurada' ) );
        }
        $name  = $product->get_name();
        $price = $product->get_price();
        $prompt = 'Eres un experto en marketing. Para el producto "' . $name . '" con precio ' . $price . ', genera un JSON con las claves "headlines" (5 ítems, máximo 30 caracteres), "descriptions" (3 ítems, máximo 90 caracteres) y "keywords" (lista de palabras clave). Responde solo JSON.';
        $response = wp_remote_post(
            'https://api.openai.com/v1/chat/completions',
            array(
                'headers' => array(
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $api_key,
                ),
                'body'    => wp_json_encode(
                    array(
                        'model'    => 'gpt-3.5-turbo',
                        'messages' => array(
                            array(
                                'role'    => 'user',
                                'content' => $prompt,
                            ),
                        ),
                    )
                ),
                'timeout' => 30,
            )
        );
        if ( is_wp_error( $response ) ) {
            $error_message = $response->get_error_message();
            if ( false !== stripos( $error_message, 'timed out' ) || false !== stripos( $error_message, 'timeout' ) ) {
                $msg = 'La solicitud a OpenAI demoró demasiado (timeout). Intenta nuevamente o aumenta los recursos del servidor.';
            } else {
                $msg = 'Error de conexión con OpenAI: tu servidor no logra conectarse. Revisa el firewall del hosting y asegúrate de permitir salida HTTPS hacia api.openai.com (puerto 443).';
            }
            wp_send_json_error( array( 'message' => $msg ) );
        }
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset( $data['error']['message'] ) ) {
            wp_send_json_error( array( 'message' => $data['error']['message'] ) );
        }
        if ( ! isset( $data['choices'][0]['message']['content'] ) ) {
            wp_send_json_error( array( 'message' => 'Respuesta inválida de OpenAI' ) );
        }
        $json = json_decode( $data['choices'][0]['message']['content'], true );
        if ( ! $json || ! isset( $json['headlines'] ) ) {
            wp_send_json_error( array( 'message' => 'No se pudo parsear la respuesta de GPT' ) );
        }
        wp_send_json_success( $json );
    }
}

