<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class B2Sell_SEM_Campaigns {

    public function render_admin_page() {
        $tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'googleads';
        echo '<div class="wrap">';
        echo '<h1>Campañas SEM</h1>';
        echo '<p>Integración con Google Ads disponible en fase 2</p>';

        echo '<h2 class="nav-tab-wrapper">';
        echo '<a href="?page=b2sell-seo-campanas&tab=googleads" class="nav-tab ' . ( 'googleads' === $tab ? 'nav-tab-active' : '' ) . '">Conexión con Google Ads API</a>';
        echo '<a href="?page=b2sell-seo-campanas&tab=campanas" class="nav-tab ' . ( 'campanas' === $tab ? 'nav-tab-active' : '' ) . '">Lectura de campañas activas</a>';
        echo '<a href="?page=b2sell-seo-campanas&tab=gpt" class="nav-tab ' . ( 'gpt' === $tab ? 'nav-tab-active' : '' ) . '">Sugerencias de keywords y copys</a>';
        echo '<a href="?page=b2sell-seo-campanas&tab=woocommerce" class="nav-tab ' . ( 'woocommerce' === $tab ? 'nav-tab-active' : '' ) . '">Integración con WooCommerce</a>';
        echo '</h2>';

        switch ( $tab ) {
            case 'campanas':
                echo '<h3>Lectura de campañas activas</h3>';
                echo '<table class="widefat fixed"><thead><tr><th>Campaña</th><th>Estado</th></tr></thead><tbody><tr><td colspan="2">Sin campañas disponibles.</td></tr></tbody></table>';
                break;
            case 'gpt':
                echo '<h3>Sugerencias de keywords y copys con GPT</h3>';
                echo '<p>Este espacio mostrará sugerencias generadas con GPT.</p>';
                break;
            case 'woocommerce':
                echo '<h3>Integración con WooCommerce</h3>';
                echo '<p>Si WooCommerce está activo, aquí se mostrarán los productos para campañas.</p>';
                break;
            case 'googleads':
            default:
                echo '<h3>Conexión con Google Ads API</h3>';
                echo '<p>Aquí podrás ingresar tus credenciales de Google Ads (sin lógica aún).</p>';
                echo '<form method="post"><table class="form-table">';
                echo '<tr><th scope="row"><label for="b2sell_googleads_client_id">Client ID</label></th><td><input type="text" id="b2sell_googleads_client_id" class="regular-text" /></td></tr>';
                echo '<tr><th scope="row"><label for="b2sell_googleads_client_secret">Client Secret</label></th><td><input type="text" id="b2sell_googleads_client_secret" class="regular-text" /></td></tr>';
                echo '</table></form>';
                break;
        }

        echo '<hr />';
        echo '<p style="font-size:12px;color:#666;">Desarrollado por B2Sell SPA.</p>';
        echo '</div>';
    }
}
