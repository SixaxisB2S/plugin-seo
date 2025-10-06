<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class B2Sell_Competencia {

    private $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'b2sell_comp_history';
        add_action( 'wp_ajax_b2sell_competencia_search', array( $this, 'ajax_search' ) );
        add_action( 'wp_ajax_b2sell_competencia_optimize', array( $this, 'ajax_optimize' ) );
        add_action( 'wp_ajax_b2sell_competencia_insert', array( $this, 'ajax_insert' ) );
        add_action( 'wp_ajax_b2sell_competencia_history_detail', array( $this, 'ajax_history_detail' ) );
        add_action( 'wp_ajax_b2sell_competencia_reanalyze', array( $this, 'ajax_reanalyze' ) );
        add_action( 'wp_ajax_b2sell_competencia_interpret', array( $this, 'ajax_interpret' ) );
    }

    private function is_sequential_array( $array ) {
        if ( ! is_array( $array ) ) {
            return false;
        }
        $expected = 0;
        foreach ( $array as $key => $value ) {
            if ( $key !== $expected ) {
                return false;
            }
            $expected++;
        }
        return true;
    }

    private function build_query_plan( $provider, $result_count ) {
        $result_count = in_array( $result_count, array( 10, 20, 40 ), true ) ? $result_count : 40;
        if ( 'serpapi' === $provider ) {
            if ( 10 === $result_count ) {
                return array(
                    array(
                        'start' => 0,
                        'num'   => 10,
                    ),
                );
            }
            if ( 20 === $result_count ) {
                return array(
                    array(
                        'start' => 0,
                        'num'   => 20,
                    ),
                );
            }
            return array(
                array(
                    'start' => 0,
                    'num'   => 20,
                ),
                array(
                    'start' => 20,
                    'num'   => 20,
                ),
            );
        }
        $remaining = $result_count;
        $start     = 1;
        $plan      = array();
        while ( $remaining > 0 ) {
            $num     = min( 10, $remaining );
            $plan[]  = array(
                'start' => $start,
                'num'   => $num,
            );
            $start   += $num;
            $remaining -= $num;
        }
        return $plan;
    }

    private function maybe_get_cached_analysis( $keyword, $provider, $result_count, $competitors, $now ) {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE keyword=%s AND provider=%s AND date >= DATE_SUB(%s, INTERVAL 1 DAY) ORDER BY date DESC LIMIT 1",
                $keyword,
                $provider,
                $now
            )
        );
        if ( ! $row ) {
            return null;
        }
        $meta_data         = json_decode( $row->keywords, true );
        $available_results = 0;
        $volume            = 0;
        if ( is_array( $meta_data ) ) {
            if ( isset( $meta_data['result_count'] ) ) {
                $available_results = intval( $meta_data['result_count'] );
            } elseif ( $this->is_sequential_array( $meta_data ) ) {
                $available_results = 50; // Compatibilidad con registros antiguos.
            }
            if ( isset( $meta_data['volumes'] ) && is_array( $meta_data['volumes'] ) ) {
                if ( isset( $meta_data['volumes'][ $keyword ] ) ) {
                    $volume = (int) $meta_data['volumes'][ $keyword ];
                } else {
                    $keyword_lower = strtolower( $keyword );
                    foreach ( $meta_data['volumes'] as $stored_keyword => $stored_volume ) {
                        if ( strtolower( (string) $stored_keyword ) === $keyword_lower ) {
                            $volume = (int) $stored_volume;
                            break;
                        }
                    }
                }
            }
        }
        if ( $available_results && $available_results < $result_count ) {
            return null;
        }
        $stored_results = json_decode( $row->results, true );
        if ( ! is_array( $stored_results ) ) {
            return null;
        }
        $stored_map = array();
        foreach ( $stored_results as $entry ) {
            $domain = $entry['domain'] ?? '';
            if ( $domain ) {
                $stored_map[ $domain ] = intval( $entry['rank'] ?? 0 );
            }
        }
        foreach ( $competitors as $domain ) {
            if ( ! array_key_exists( $domain, $stored_map ) ) {
                return null;
            }
        }
        $comp_ranks = array();
        foreach ( $competitors as $domain ) {
            $comp_ranks[ $domain ] = $stored_map[ $domain ];
        }
        return array(
            'mine'        => (int) $row->my_rank,
            'competitors' => $comp_ranks,
            'volume'      => $volume,
        );
    }

    private function ctr_from_rank( $rank ) {
        if ( 1 === $rank ) {
            return 0.28;
        }
        if ( 2 === $rank ) {
            return 0.15;
        }
        if ( 3 === $rank ) {
            return 0.10;
        }
        if ( 4 === $rank ) {
            return 0.07;
        }
        if ( 5 === $rank ) {
            return 0.05;
        }
        if ( $rank >= 6 && $rank <= 10 ) {
            return 0.03;
        }
        if ( $rank >= 11 && $rank <= 20 ) {
            return 0.01;
        }
        return 0;
    }

    private function get_keyword_volumes( $keywords ) {
        $dev_token     = get_option( 'b2sell_ads_developer_token', '' );
        $client_id     = get_option( 'b2sell_ads_client_id', '' );
        $client_secret = get_option( 'b2sell_ads_client_secret', '' );
        $refresh_token = get_option( 'b2sell_ads_refresh_token', '' );
        $customer_id   = get_option( 'b2sell_ads_customer_id', '' );
        if ( ! $dev_token || ! $client_id || ! $client_secret || ! $refresh_token || ! $customer_id ) {
            return array();
        }
        $token_res = wp_remote_post(
            'https://oauth2.googleapis.com/token',
            array(
                'body' => array(
                    'client_id'     => $client_id,
                    'client_secret' => $client_secret,
                    'refresh_token' => $refresh_token,
                    'grant_type'    => 'refresh_token',
                ),
            )
        );
        if ( is_wp_error( $token_res ) ) {
            return array();
        }
        $token_data = json_decode( wp_remote_retrieve_body( $token_res ), true );
        $access     = $token_data['access_token'] ?? '';
        if ( ! $access ) {
            return array();
        }
        $url      = 'https://googleads.googleapis.com/v14/customers/' . rawurlencode( $customer_id ) . ':generateKeywordIdeas';
        $body     = array(
            'keywordSeed'         => array( 'keywords' => array_values( $keywords ) ),
            'includeAdultKeywords'=> false,
        );
        $response = wp_remote_post(
            $url,
            array(
                'headers' => array(
                    'Authorization'   => 'Bearer ' . $access,
                    'developer-token' => $dev_token,
                    'Content-Type'    => 'application/json',
                ),
                'body'    => wp_json_encode( $body ),
            )
        );
        if ( is_wp_error( $response ) ) {
            return array();
        }
        $data    = json_decode( wp_remote_retrieve_body( $response ), true );
        $volumes = array();
        if ( ! empty( $data['results'] ) ) {
            foreach ( $data['results'] as $res ) {
                $text   = $res['text'] ?? '';
                $volume = $res['keywordIdeaMetrics']['avgMonthlySearches'] ?? 0;
                if ( $text ) {
                    $volumes[ $text ] = (int) $volume;
                }
            }
        }
        return $volumes;
    }

    public static function install() {
        global $wpdb;
        $table = $wpdb->prefix . 'b2sell_comp_history';
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            date DATETIME NOT NULL,
            keyword VARCHAR(255) NOT NULL,
            keywords LONGTEXT NOT NULL,
            post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            results LONGTEXT NOT NULL,
            my_rank INT NOT NULL DEFAULT 0,
            provider VARCHAR(20) NOT NULL DEFAULT 'google',
            PRIMARY KEY  (id),
            KEY keyword (keyword)
        ) $charset_collate;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Obtiene histórico de rankings para el dashboard
     *
     * @return array
     */
    public function get_dashboard_history() {
        global $wpdb;
        $rows = $wpdb->get_results( "SELECT keyword,date,my_rank,results FROM {$this->table} ORDER BY keyword,date ASC" );
        $data = array();
        foreach ( $rows as $row ) {
            $keyword = $row->keyword;
            if ( ! isset( $data[ $keyword ] ) ) {
                $data[ $keyword ] = array(
                    'dates'       => array(),
                    'mine'        => array(),
                    'competitors' => array(),
                );
            }
            $kdata =& $data[ $keyword ];
            $kdata['dates'][] = $row->date;
            $kdata['mine'][]  = (int) $row->my_rank;

            foreach ( $kdata['competitors'] as &$carr ) {
                $carr[] = null;
            }
            unset( $carr );

            $items = json_decode( $row->results, true );
            if ( $items ) {
                $items = array_slice( $items, 0, 5 );
                foreach ( $items as $it ) {
                    $domain = $it['domain'] ?? '';
                    if ( ! $domain ) {
                        continue;
                    }
                    if ( ! isset( $kdata['competitors'][ $domain ] ) ) {
                        if ( count( $kdata['competitors'] ) >= 5 ) {
                            continue;
                        }
                        $kdata['competitors'][ $domain ] = array_fill( 0, count( $kdata['dates'] ), null );
                    }
                    $kdata['competitors'][ $domain ][ count( $kdata['dates'] ) - 1 ] = isset( $it['rank'] ) ? (int) $it['rank'] : null;
                }
            }
        }
        return $data;
    }

    public function get_visibility_history( $limit = 50 ) {
        global $wpdb;
        $limit     = max( 0, (int) $limit );
        $raw_limit = $limit ? $limit * 10 : 200;
        if ( $raw_limit <= 0 ) {
            $raw_limit = 200;
        }
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT date,keywords FROM {$this->table} ORDER BY date DESC LIMIT %d",
                $raw_limit
            )
        );
        $history = array();
        foreach ( $rows as $row ) {
            if ( isset( $history[ $row->date ] ) ) {
                continue;
            }
            $meta = json_decode( $row->keywords, true );
            if ( ! is_array( $meta ) ) {
                continue;
            }
            $visibility_meta = $meta['visibility'] ?? array();
            if ( isset( $visibility_meta['normalized'] ) && is_array( $visibility_meta['normalized'] ) ) {
                $visibility = $visibility_meta['normalized'];
            } elseif ( is_array( $visibility_meta ) ) {
                $visibility = $visibility_meta;
            } else {
                continue;
            }
            if ( empty( $visibility ) ) {
                continue;
            }
            $history[ $row->date ] = array(
                'date'       => $row->date,
                'visibility' => array_map(
                    static function ( $value ) {
                        return is_numeric( $value ) ? (float) $value : 0.0;
                    },
                    $visibility
                ),
            );
        }
        if ( $history ) {
            ksort( $history );
            if ( $limit > 0 && count( $history ) > $limit ) {
                $history = array_slice( $history, -$limit, null, true );
            }
        }
        return array_values( $history );
    }

    private function calculate_position_changes( $keywords, $provider, $results ) {
        global $wpdb;
        $summary = array(
            'up'    => 0,
            'down'  => 0,
            'equal' => 0,
        );
        $items = array();
        if ( empty( $keywords ) ) {
            return array(
                'summary' => $summary,
                'items'   => $items,
            );
        }
        foreach ( $keywords as $keyword ) {
            $keyword = (string) $keyword;
            if ( '' === $keyword ) {
                continue;
            }
            $current_rank = isset( $results[ $keyword ]['mine'] ) ? (int) $results[ $keyword ]['mine'] : 0;
            $rows         = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT my_rank FROM {$this->table} WHERE keyword=%s AND provider=%s ORDER BY date DESC LIMIT 2",
                    $keyword,
                    $provider
                )
            );
            $previous_rank = null;
            if ( $rows ) {
                $latest_rank = isset( $rows[0] ) ? (int) $rows[0]->my_rank : 0;
                if ( 0 === $current_rank && $latest_rank > 0 ) {
                    $current_rank = $latest_rank;
                }
                if ( isset( $rows[1] ) ) {
                    $previous_rank = (int) $rows[1]->my_rank;
                }
            }
            $direction    = 'equal';
            $change_label = '=';
            if ( null !== $previous_rank ) {
                if ( $current_rank > 0 && $previous_rank > 0 ) {
                    $delta = $previous_rank - $current_rank;
                    if ( $delta > 0 ) {
                        $direction    = 'up';
                        $change_label = '+' . $delta;
                    } elseif ( $delta < 0 ) {
                        $direction    = 'down';
                        $change_label = (string) $delta;
                    }
                } elseif ( $previous_rank > 0 && $current_rank <= 0 ) {
                    $direction    = 'down';
                    $change_label = 'Perdido';
                } elseif ( $previous_rank <= 0 && $current_rank > 0 ) {
                    $direction    = 'up';
                    $change_label = 'Nuevo';
                }
            }
            if ( 'up' === $direction ) {
                $summary['up']++;
            } elseif ( 'down' === $direction ) {
                $summary['down']++;
            } else {
                $summary['equal']++;
            }
            $items[] = array(
                'keyword'      => $keyword,
                'previous'     => $previous_rank,
                'current'      => $current_rank,
                'direction'    => $direction,
                'change_label' => $change_label,
            );
        }
        return array(
            'summary' => $summary,
            'items'   => $items,
        );
    }

    public function render_admin_page() {
        $posts = get_posts( array(
            'post_type'   => array( 'post', 'page' ),
            'post_status' => 'publish',
            'numberposts' => -1,
        ) );
        $nonce    = wp_create_nonce( 'b2sell_competencia_nonce' );
        $posts_js = array();
        foreach ( $posts as $p ) {
            $meta = get_post_meta( $p->ID, '_b2sell_meta_description', true );
            if ( ! $meta ) {
                $meta = wp_trim_words( $p->post_content, 30 );
            }
            $posts_js[ $p->ID ] = array(
                'title' => $p->post_title,
                'meta'  => $meta,
                'url'   => get_permalink( $p->ID ),
            );
        }
        $api_key     = get_option( 'b2sell_google_api_key', '' );
        $cx          = get_option( 'b2sell_google_cx', '' );
        $serpapi_key = get_option( 'b2sell_serpapi_key', '' );
        $provider    = get_option( 'b2sell_comp_provider', 'google' );
        $competitors = get_option( 'b2sell_comp_domains', array() );
        $site_domain = parse_url( home_url(), PHP_URL_HOST );
        $site_domain = $site_domain ? $site_domain : '';
        global $wpdb;
        $history = $wpdb->get_results( "SELECT * FROM {$this->table} ORDER BY date DESC LIMIT 20" );
        $vis_history      = $this->get_visibility_history();
        $vis_history_json = wp_json_encode( $vis_history );
        if ( false === $vis_history_json ) {
            $vis_history_json = '[]';
        }
        echo '<div class="wrap">';
        if ( ( 'google' === $provider && ( ! $api_key || ! $cx ) ) || ( 'serpapi' === $provider && ! $serpapi_key ) ) {
            $link = esc_url( admin_url( 'admin.php?page=b2sell-seo-config' ) );
            $msg  = ( 'google' === $provider ) ? 'Google Custom Search no está configurado. Configura la API Key y el ID del motor de búsqueda (CX)' : 'SerpAPI no está configurado. Configura la API Key';
            echo '<div class="error"><p>' . $msg . ' en la <a href="' . $link . '">página de Configuración</a>.</p></div>';
        }
        echo '<h1>Competencia</h1>';
        echo '<h2 class="nav-tab-wrapper"><a href="#" class="nav-tab nav-tab-active" data-tab="analysis">Análisis</a><a href="#" class="nav-tab" data-tab="history">Histórico</a></h2>';

        echo '<div id="b2sell_comp_tab_analysis" class="b2sell-comp-tab">';
        echo '<style id="b2sell_comp_panel_styles">';
        echo '.b2sell-comp-input-grid{display:flex;flex-wrap:wrap;gap:24px;margin-top:15px;align-items:flex-start;}';
        echo '.b2sell-comp-input-block{flex:1 1 280px;background:#ffffff;border-radius:12px;padding:16px;box-shadow:0 12px 30px rgba(15,23,42,0.08);}';
        echo '.b2sell-comp-input-block h3{margin:0 0 12px;font-size:16px;font-weight:600;color:#0f172a;}';
        echo '.b2sell-comp-input-block textarea{width:100%;height:120px;resize:vertical;}';
        echo '.b2sell-comp-domain-row{display:flex;gap:8px;align-items:center;}';
        echo '#b2sell_comp_domain_input{flex:1;}';
        echo '#b2sell_comp_add_domain{display:inline-flex;align-items:center;gap:6px;}';
        echo '#b2sell_comp_add_domain .dashicons{width:16px;height:16px;font-size:16px;}';
        echo '.b2sell-comp-domain-list{list-style:none;margin:12px 0 0;padding:0;display:flex;flex-wrap:wrap;gap:10px;}';
        echo '.b2sell-comp-domain-item{display:flex;align-items:center;gap:6px;background:#f1f5f9;border-radius:999px;padding:6px 10px;}';
        echo '.b2sell-comp-domain-text{font-weight:500;color:#0f172a;}';
        echo '.b2sell-comp-domain-empty{color:#6b7280;font-style:italic;}';
        echo '.b2sell-comp-remove-domain{color:#ef4444;}';
        echo '.b2sell-comp-remove-domain .dashicons{width:16px;height:16px;font-size:16px;}';
        echo '@media (max-width:782px){.b2sell-comp-input-block{flex:1 1 100%;}}';
        echo '</style>';
        echo '<p>Ingresa hasta 10 palabras clave (una por línea) y gestiona hasta 5 dominios competidores con el botón "Añadir".</p>';
        echo '<div class="b2sell-comp-input-grid">';
        echo '<div class="b2sell-comp-input-block">';
        echo '<h3>Palabras clave</h3>';
        echo '<textarea id="b2sell_comp_keywords" placeholder="Palabras clave"></textarea>';
        echo '<p class="description">Una keyword por línea. Máximo 10.</p>';
        echo '</div>';
        echo '<div class="b2sell-comp-input-block">';
        echo '<h3>Competidores</h3>';
        echo '<div class="b2sell-comp-domain-row">';
        echo '<input type="text" id="b2sell_comp_domain_input" placeholder="competidor.com" class="regular-text" />';
        echo '<button type="button" class="button" id="b2sell_comp_add_domain"><span class="dashicons dashicons-plus-alt2"></span> Añadir</button>';
        echo '</div>';
        echo '<p class="description" id="b2sell_comp_domain_help">Añade hasta 5 dominios competidores relevantes.</p>';
        echo '<ul id="b2sell_comp_domain_list" class="b2sell-comp-domain-list"></ul>';
        echo '</div>';
        echo '</div>';
        echo '<div style="margin-top:15px;">';
        echo '<label for="b2sell_comp_result_count">Resultados a analizar por keyword:</label> ';
        echo '<select id="b2sell_comp_result_count" style="margin-left:10px;">';
        echo '<option value="10">Primeras 10 posiciones</option>';
        echo '<option value="20">Primeras 20 posiciones</option>';
        echo '<option value="40" selected>Primeras 40 posiciones</option>';
        echo '</select>';
        echo '</div>';
        echo '<p class="description" id="b2sell_comp_query_notice" style="margin-top:10px;">Los análisis realizados en las últimas 24 horas se reutilizarán automáticamente.</p>';
        echo '<button class="button" id="b2sell_comp_search_btn">Buscar</button>';
        echo '<div id="b2sell_comp_results" style="margin-top:20px;"></div>';
        echo '</div>';

        echo '<div id="b2sell_comp_tab_history" class="b2sell-comp-tab" style="display:none;">';
        echo '<table class="widefat"><thead><tr><th>Fecha</th><th>Keyword</th><th>Ranking competidores</th><th>Proveedor</th></tr></thead><tbody>';
        if ( $history ) {
            foreach ( $history as $row ) {
                $results   = json_decode( $row->results, true );
                $rank_html = '';
                if ( $results ) {
                    foreach ( $results as $res ) {
                        $rank_html .= esc_html( $res['domain'] ) . ': ' . intval( $res['rank'] ) . '<br>';
                    }
                }
                $prov_label = ( 'serpapi' === $row->provider ) ? 'SerpAPI' : 'Google Custom Search';
                echo '<tr><td>' . esc_html( $row->date ) . '</td><td>' . esc_html( $row->keyword ) . '</td><td>' . $rank_html . '</td><td>' . esc_html( $prov_label ) . '</td></tr>';
            }
        } else {
            echo '<tr><td colspan="4">Sin análisis previos.</td></tr>';
        }
        echo '</tbody></table>';
        $history_has_data    = ! empty( $vis_history );
        $history_wrapper_css = $history_has_data ? '' : 'display:none;';
        $history_empty_css   = $history_has_data ? 'display:none;' : '';
        echo '<div id="b2sell_comp_visibility_history_container" style="margin-top:20px;">';
        echo '<h3>Índice de visibilidad en el tiempo</h3>';
        echo '<div class="b2sell-comp-chart-wrapper" style="position:relative;height:260px;' . $history_wrapper_css . '">';
        echo '<canvas id="b2sell_comp_visibility_history_chart" style="width:100%;height:100%;"></canvas>';
        echo '</div>';
        echo '<p id="b2sell_comp_visibility_history_empty" style="margin-top:10px;' . $history_empty_css . '">Sin datos de visibilidad.</p>';
        echo '</div>';
        echo '</div>';

        echo '</div>';

        echo '<div id="b2sell_comp_modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);">';
        echo '<div style="background:#fff;padding:20px;max-width:600px;margin:50px auto;">';
        echo '<h2>Sugerencias GPT</h2><div id="b2sell_comp_suggestions"></div>';
        echo '<button class="button" id="b2sell_comp_copy">Copiar</button> <button class="button" id="b2sell_comp_export">Exportar CSV</button> <button class="button button-primary" id="b2sell_comp_insert">Insertar en el contenido</button> <button class="button" id="b2sell_comp_close">Cerrar</button>';
        echo '</div></div>';

        echo '<div id="b2sell_comp_hist_modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);">';
        echo '<div style="background:#fff;padding:20px;max-width:800px;margin:50px auto;"><div id="b2sell_comp_hist_modal_content"></div><button class="button" id="b2sell_comp_hist_close">Cerrar</button></div></div>';
        echo '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';
        $competitors_json = wp_json_encode( array_values( $competitors ) );
        if ( false === $competitors_json ) {
            $competitors_json = '[]';
        }
        echo '<script>var b2sellCompNonce="' . esc_js( $nonce ) . '",b2sellCompProvider="' . esc_js( $provider ) . '",b2sellCompVisibilityHistory=' . $vis_history_json . ',b2sellCompPrimaryDomain="' . esc_js( $site_domain ) . '",b2sellCompInitialDomains=' . $competitors_json . ';</script>';
        echo '<script>
        jQuery(function($){
            $(".nav-tab-wrapper .nav-tab").on("click",function(e){e.preventDefault();var t=$(this).data("tab");$(".nav-tab").removeClass("nav-tab-active");$(this).addClass("nav-tab-active");$(".b2sell-comp-tab").hide();$("#b2sell_comp_tab_"+t).show();});
            var baseNotice="Los análisis realizados en las últimas 24 horas se reutilizarán automáticamente.";
            function getKeywords(){
                return $("#b2sell_comp_keywords").val().split(/\\n+/)
                    .map(function(s){return $.trim(s);})
                    .filter(function(s){return s.length;})
                    .slice(0,10);
            }
            function updateNotice(){
                var kws=getKeywords();
                var resultCount=parseInt($("#b2sell_comp_result_count").val(),10)||40;
                var perKeyword=1;
                if("serpapi"===b2sellCompProvider){
                    perKeyword=resultCount>=40?2:1;
                }else{
                    perKeyword=Math.ceil(resultCount/10);
                }
                if(!kws.length){
                    $("#b2sell_comp_query_notice").text(baseNotice);
                    return;
                }
                var total=perKeyword*kws.length;
                var text="Se estiman aproximadamente "+total+" consultas a la API ("+perKeyword+" por keyword). "+baseNotice;
                $("#b2sell_comp_query_notice").text(text);
            }
            $("#b2sell_comp_keywords").on("input",updateNotice);
            $("#b2sell_comp_result_count").on("change",updateNotice);
            updateNotice();
            var maxCompetitors=5;
            var domainInput=$("#b2sell_comp_domain_input");
            var domainAddBtn=$("#b2sell_comp_add_domain");
            var domainList=$("#b2sell_comp_domain_list");
            var domainHelp=$("#b2sell_comp_domain_help");
            var defaultDomainHelp=domainHelp.text();
            var compDomains=Array.isArray(window.b2sellCompInitialDomains)?window.b2sellCompInitialDomains.slice(0,maxCompetitors):[];
            function normalizeDomain(domain){
                if(!domain){return "";}
                domain=$.trim(domain);
                if(!domain){return "";}
                domain=domain.replace(/^https?:\/\//i,"");
                domain=domain.replace(/\s+/g,"");
                domain=domain.replace(/\/.*$/,"");
                return domain.toLowerCase();
            }
            function renderDomainList(){
                domainList.empty();
                if(!compDomains.length){
                    domainList.append($("<li>",{"class":"b2sell-comp-domain-empty"}).text("No has añadido competidores."));
                }else{
                    compDomains.forEach(function(domain,index){
                        var item=$("<li>",{"class":"b2sell-comp-domain-item"});
                        $("<span>",{"class":"b2sell-comp-domain-text"}).text(domain).appendTo(item);
                        var removeBtn=$("<button>",{type:"button","class":"button-link-delete b2sell-comp-remove-domain","data-index":index,"aria-label":"Eliminar "+domain});
                        $("<span>",{"class":"dashicons dashicons-no-alt"}).appendTo(removeBtn);
                        removeBtn.appendTo(item);
                        domainList.append(item);
                    });
                }
                var disabled=compDomains.length>=maxCompetitors;
                domainInput.prop("disabled",disabled);
                domainAddBtn.prop("disabled",disabled);
                if(disabled){
                    domainInput.attr("placeholder","Límite alcanzado");
                    domainHelp.text("Has llegado al máximo de 5 dominios. Elimina alguno para añadir nuevos.");
                }else{
                    domainInput.attr("placeholder","competidor.com");
                    domainHelp.text(defaultDomainHelp);
                }
            }
            function addDomain(domain){
                if(!domain){return;}
                if(compDomains.indexOf(domain)!==-1){
                    domainInput.val("");
                    return;
                }
                if(compDomains.length>=maxCompetitors){
                    return;
                }
                compDomains.push(domain);
                domainInput.val("");
                renderDomainList();
            }
            domainAddBtn.on("click",function(e){
                e.preventDefault();
                var domain=normalizeDomain(domainInput.val());
                addDomain(domain);
                domainInput.focus();
            });
            domainInput.on("keypress",function(e){
                if(13===e.which){
                    e.preventDefault();
                    var domain=normalizeDomain($(this).val());
                    addDomain(domain);
                }
            });
            domainList.on("click",".b2sell-comp-remove-domain",function(e){
                e.preventDefault();
                var idx=parseInt($(this).data("index"),10);
                if(isNaN(idx)){
                    return;
                }
                compDomains.splice(idx,1);
                renderDomainList();
            });
            renderDomainList();
            var visColors=["#0073aa","#ff6384","#36a2eb","#ffcd56","#4bc0c0","#9966ff"];
            var visPrimaryColor=visColors[0]||"#0073aa";
            var defaultAltColors=["#ff6384","#36a2eb","#ffcd56","#4bc0c0","#9966ff","#c9cbcf"];
            var visAltColors=visColors.length>1?visColors.slice(1).concat(defaultAltColors):defaultAltColors;
            var visHistoryCharts={};
            var visHistory=Array.isArray(b2sellCompVisibilityHistory)?b2sellCompVisibilityHistory.slice():[];
            function orderDomains(domains,primary){
                var list=domains.slice();
                list.sort(function(a,b){
                    if(primary){
                        if(a===primary){return -1;}
                        if(b===primary){return 1;}
                    }
                    return a.localeCompare(b);
                });
                return list;
            }
            function getColorsForDomains(domains,primary){
                var colors=[];
                var altIndex=0;
                domains.forEach(function(dom){
                    if(primary&&dom===primary){
                        colors.push(visPrimaryColor);
                    }else{
                        colors.push(visAltColors[altIndex%visAltColors.length]);
                        altIndex++;
                    }
                });
                return colors;
            }
            function destroyHistoryChart(id){
                if(visHistoryCharts[id]){
                    visHistoryCharts[id].destroy();
                    delete visHistoryCharts[id];
                }
            }
            function renderVisibilityChart(vis){
                var canvas=document.getElementById("b2sell_comp_visibility_chart");
                var valuesWrap=$("#b2sell_comp_visibility_values");
                if(!canvas||typeof Chart==="undefined"){
                    if(valuesWrap.length){valuesWrap.empty();}
                    return;
                }
                var normalized=vis&&vis.normalized?vis.normalized:{};
                var labels=orderDomains(Object.keys(normalized),b2sellCompPrimaryDomain||"");
                if(!labels.length){
                    if(valuesWrap.length){valuesWrap.empty();}
                    if(window.b2sellCompVisibilityChart){window.b2sellCompVisibilityChart.destroy();}
                    return;
                }
                var colors=getColorsForDomains(labels,b2sellCompPrimaryDomain||"");
                var values=labels.map(function(label){return parseFloat(normalized[label]||0);});
                if(window.b2sellCompVisibilityChart){window.b2sellCompVisibilityChart.destroy();}
                window.b2sellCompVisibilityChart=new Chart(canvas.getContext("2d"),{type:"bar",data:{labels:labels,datasets:[{data:values,backgroundColor:colors}]},options:{responsive:true,maintainAspectRatio:false,scales:{y:{beginAtZero:true,max:100}},plugins:{legend:{display:false}}}});
                if(valuesWrap.length){
                    var valuesHtml="";
                    labels.forEach(function(label,index){
                        var val=values[index];
                        valuesHtml+="<div style=\"flex:1;text-align:center;\"><strong>"+label+"</strong><div>"+val.toFixed(1)+"</div></div>";
                    });
                    valuesWrap.html(valuesHtml);
                }
            }
            function sortHistory(){
                visHistory.sort(function(a,b){return new Date(a.date)-new Date(b.date);});
            }
            function renderVisibilityHistoryChart(canvasId,emptyId,primaryDomain){
                var canvas=document.getElementById(canvasId);
                var empty=emptyId?document.getElementById(emptyId):null;
                if(!canvas||typeof Chart==="undefined"){
                    return;
                }
                var wrapper=canvas.parentElement;
                if(!visHistory.length){
                    if(empty){empty.style.display="block";}
                    if(wrapper){wrapper.style.display="none";}
                    canvas.style.display="none";
                    destroyHistoryChart(canvasId);
                    return;
                }
                sortHistory();
                var historyData=visHistory;
                var labels=historyData.map(function(item){return item.date?item.date.split(" ")[0]:item.date;});
                var tooltipDates=historyData.map(function(item){return item.date;});
                var domainMap={};
                historyData.forEach(function(item){
                    if(item.visibility){
                        Object.keys(item.visibility).forEach(function(dom){domainMap[dom]=true;});
                    }
                });
                var highlightDomain=primaryDomain||b2sellCompPrimaryDomain||"";
                var domains=orderDomains(Object.keys(domainMap),highlightDomain);
                if(!domains.length){
                    if(empty){empty.style.display="block";}
                    if(wrapper){wrapper.style.display="none";}
                    canvas.style.display="none";
                    destroyHistoryChart(canvasId);
                    return;
                }
                if(empty){empty.style.display="none";}
                if(wrapper){wrapper.style.display="block";}
                canvas.style.display="block";
                var altIndex=0;
                var datasets=domains.map(function(dom){
                    var isPrimary=highlightDomain&&dom===highlightDomain;
                    var color;
                    if(isPrimary){
                        color=visPrimaryColor;
                    }else{
                        color=visAltColors[altIndex%visAltColors.length];
                        altIndex++;
                    }
                    return {
                        label:dom,
                        data:historyData.map(function(item){
                            if(item.visibility&&Object.prototype.hasOwnProperty.call(item.visibility,dom)){
                                var val=parseFloat(item.visibility[dom]);
                                if(isNaN(val)){return null;}
                                if(val>100){val=100;}
                                if(val<0){val=0;}
                                return val;
                            }
                            return null;
                        }),
                        borderColor:color,
                        backgroundColor:color,
                        fill:false,
                        borderWidth:isPrimary?3:2,
                        pointRadius:isPrimary?4:3,
                        pointHoverRadius:isPrimary?6:4,
                        tension:0.3,
                        spanGaps:true
                    };
                });
                destroyHistoryChart(canvasId);
                visHistoryCharts[canvasId]=new Chart(canvas.getContext("2d"),{
                    type:"line",
                    data:{labels:labels,datasets:datasets},
                    options:{
                        responsive:true,
                        maintainAspectRatio:false,
                        interaction:{mode:"index",intersect:false},
                        scales:{
                            x:{title:{display:true,text:"Fecha"}},
                            y:{
                                beginAtZero:true,
                                max:100,
                                title:{display:true,text:"Índice de visibilidad"},
                                ticks:{callback:function(value){return value+"%";}}
                            }
                        },
                        plugins:{
                            legend:{display:true,position:"top",labels:{usePointStyle:true}},
                            tooltip:{
                                callbacks:{
                                    title:function(context){
                                        if(!context.length){return"";}
                                        var idx=context[0].dataIndex;
                                        var raw=tooltipDates[idx]||context[0].label;
                                        return "Fecha: "+raw;
                                    },
                                    label:function(context){
                                        var value=typeof context.parsed.y==="number"?context.parsed.y.toFixed(1):context.formattedValue;
                                        return context.dataset.label+": "+value+"%";
                                    }
                                }
                            }
                        }
                    }
                });
            }
            renderVisibilityHistoryChart("b2sell_comp_visibility_history_chart","b2sell_comp_visibility_history_empty",b2sellCompPrimaryDomain);
            $("#b2sell_comp_search_btn").on("click", function(){
                var kws=getKeywords();
                var doms=compDomains.slice();
                if(!kws.length){return;}
                var resultCount=parseInt($("#b2sell_comp_result_count").val(),10)||40;
                $("#b2sell_comp_results").html("Analizando...");
                $.post(ajaxurl,{action:"b2sell_competencia_search",keywords:kws,competitors:doms,result_count:resultCount,_wpnonce:b2sellCompNonce},function(res){
                    if(res.success){
                        var data=res.data;
                        var comps=data.competitors;
                        if(!document.getElementById("b2sell_comp_growth_styles")){
                            var styleContent=[
                                ".b2sell-comp-growth{margin-top:30px;background:linear-gradient(180deg,#ffffff 0%,#f9fafb 100%);border-radius:16px;padding:24px;box-shadow:0 20px 45px rgba(15,23,42,0.08);}",
                                ".b2sell-comp-growth h3{margin:0 0 16px;font-size:20px;color:#0f172a;font-weight:600;}",
                                ".b2sell-comp-growth-summary{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:20px;}",
                                ".b2sell-comp-growth-summary .summary-card{flex:1;min-width:180px;background:rgba(255,255,255,0.92);border-radius:12px;padding:16px;box-shadow:inset 0 0 0 1px rgba(15,23,42,0.05);display:flex;flex-direction:column;gap:8px;}",
                                ".summary-card.summary-card-up{border-left:4px solid #22c55e;}",
                                ".summary-card.summary-card-down{border-left:4px solid #ef4444;}",
                                ".summary-card.summary-card-equal{border-left:4px solid #9ca3af;}",
                                ".summary-card .summary-label{font-size:12px;letter-spacing:0.08em;text-transform:uppercase;color:#6b7280;}",
                                ".summary-card .summary-value{font-size:28px;font-weight:700;color:#111827;}",
                                ".b2sell-comp-growth-table{background:#ffffff;border-radius:12px;padding:16px;box-shadow:inset 0 0 0 1px rgba(15,23,42,0.04);overflow-x:auto;}",
                                ".b2sell-comp-growth-table table{margin-top:12px;border-radius:10px;overflow:hidden;width:100%;}",
                                ".b2sell-comp-growth-table th{background:#f3f4f6;font-weight:600;color:#111827;}",
                                ".b2sell-comp-growth-table td{color:#1f2937;}",
                                ".b2sell-comp-growth-empty{margin:20px 0 0;color:#6b7280;text-align:center;}",
                                ".b2sell-change{display:inline-flex;align-items:center;gap:6px;font-weight:600;border-radius:999px;padding:6px 12px;font-size:14px;}",
                                ".b2sell-change-up{background:rgba(34,197,94,0.15);color:#047857;}",
                                ".b2sell-change-down{background:rgba(239,68,68,0.18);color:#b91c1c;}",
                                ".b2sell-change-equal{background:rgba(156,163,175,0.18);color:#374151;}",
                                ".b2sell-change .dashicons{font-size:16px;height:16px;width:16px;line-height:16px;}",
                                ".b2sell-growth-table tbody tr:nth-child(even){background:rgba(249,250,251,0.7);}",
                                ".b2sell-growth-table tbody tr:hover{background:rgba(191,219,254,0.35);}",
                                "@media (max-width:900px){.b2sell-comp-growth-summary{flex-direction:column;}.b2sell-comp-growth{padding:20px;}.b2sell-comp-growth-table{padding:12px;}}"
                            ].join("");
                            var styleTag=document.createElement("style");
                            styleTag.id="b2sell_comp_growth_styles";
                            styleTag.appendChild(document.createTextNode(styleContent));
                            document.head.appendChild(styleTag);
                        }
                        var formatPosition=function(value){return value&&value>0?value:"—";};
                        var html="<div class=\\\"b2sell-comp-table\\\"><table class=\\\"widefat\\\"><thead><tr><th>Keyword</th><th>"+data.domain+"</th>";
                        comps.forEach(function(c){html+="<th>"+c+"</th>";});
                        html+="</tr></thead><tbody>";
                        kws.forEach(function(kw){
                            var row=data.results[kw]||{};
                            html+="<tr><td>"+kw+"</td><td>"+(row.mine||"-")+"</td>";
                            comps.forEach(function(c){var r=row.competitors&&row.competitors[c]?row.competitors[c]:"-";html+="<td>"+r+"</td>";});
                            html+="</tr>";
                        });
                        html+="</tbody></table></div>";
                        var summary=data.position_summary||{};
                        var improved=parseInt(summary.up||0,10);
                        var worsened=parseInt(summary.down||0,10);
                        var unchanged=parseInt(summary.equal||0,10);
                        var changes=Array.isArray(data.position_changes)?data.position_changes:[];
                        html+="<div class=\\\"b2sell-comp-growth\\\">";
                        html+="<div class=\\\"b2sell-comp-growth-summary\\\">";
                        html+="<div class=\\\"summary-card summary-card-up\\\"><span class=\\\"summary-label\\\">Keywords que mejoraron</span><span class=\\\"summary-value\\\">"+improved+"</span></div>";
                        html+="<div class=\\\"summary-card summary-card-down\\\"><span class=\\\"summary-label\\\">Keywords que empeoraron</span><span class=\\\"summary-value\\\">"+worsened+"</span></div>";
                        html+="<div class=\\\"summary-card summary-card-equal\\\"><span class=\\\"summary-label\\\">Keywords sin cambios</span><span class=\\\"summary-value\\\">"+unchanged+"</span></div>";
                        html+="</div>";
                        html+="<div class=\\\"b2sell-comp-growth-table\\\">";
                        html+="<h3>Ranking de crecimiento/disminución</h3>";
                        if(!changes.length){
                            html+="<p class=\\\"b2sell-comp-growth-empty\\\">Sin datos históricos para mostrar el ranking de crecimiento.</p>";
                        }else{
                            html+="<table class=\\\"widefat b2sell-growth-table\\\"><thead><tr><th>Keyword</th><th>Posición anterior</th><th>Posición actual</th><th>Cambio</th></tr></thead><tbody>";
                            changes.forEach(function(item){
                                var prev=formatPosition(item.previous);
                                var curr=formatPosition(item.current);
                                var direction=item.direction||"equal";
                                var changeLabel=item.change_label||"=";
                                var changeClass="b2sell-change-equal";
                                var icon="<span class=\\\"dashicons dashicons-minus\\\"></span>";
                                if(direction==="up"){
                                    changeClass="b2sell-change-up";
                                    icon="<span class=\\\"dashicons dashicons-arrow-up-alt\\\"></span>";
                                    if(!changeLabel){changeLabel="Mejoró";}
                                }else if(direction==="down"){
                                    changeClass="b2sell-change-down";
                                    icon="<span class=\\\"dashicons dashicons-arrow-down-alt\\\"></span>";
                                    if(!changeLabel){changeLabel="Peor";}
                                }else if(!changeLabel){
                                    changeLabel="=";
                                }
                                html+="<tr><td>"+item.keyword+"</td><td>"+prev+"</td><td>"+curr+"</td><td><span class=\\\"b2sell-change "+changeClass+"\\\">"+icon+"<span>"+changeLabel+"</span></span></td></tr>";
                            });
                            html+="</tbody></table>";
                        }
                        html+="</div></div>";
                        html+="<div id=\\\"b2sell_comp_visibility_container\\\" style=\\\"margin-top:30px;\\\">";
                        html+="<h3>Índice de visibilidad</h3>";
                        html+="<canvas id=\\\"b2sell_comp_visibility_chart\\\" height=\\\"140\\\"></canvas>";
                        html+="<div id=\\\"b2sell_comp_visibility_values\\\" style=\\\"display:flex;justify-content:space-around;margin-top:10px;\\\"></div>";
                        html+="</div>";
                        html+="<div id=\\\"b2sell_comp_visibility_trend\\\" style=\\\"margin-top:30px;\\\">";
                        html+="<h3>Evolución del índice de visibilidad</h3>";
                        html+="<div class=\\\"b2sell-comp-chart-wrapper\\\" style=\\\"position:relative;height:260px;\\\">";
                        html+="<canvas id=\\\"b2sell_comp_visibility_trend_chart\\\" style=\\\"width:100%;height:100%;\\\"></canvas>";
                        html+="</div>";
                        html+="<p id=\\\"b2sell_comp_visibility_trend_empty\\\" style=\\\"display:none;margin-top:10px;\\\">Sin datos históricos de visibilidad.</p>";
                        html+="</div>";
                        destroyHistoryChart("b2sell_comp_visibility_trend_chart");
                        $("#b2sell_comp_results").html(html);
                        b2sellCompPrimaryDomain=data.domain||b2sellCompPrimaryDomain||"";
                        renderVisibilityChart(data.visibility);
                        if(data.history_entry&&data.history_entry.date){
                            var updated=false;
                            visHistory=visHistory.map(function(item){if(item.date===data.history_entry.date){updated=true;return data.history_entry;}return item;});
                            if(!updated){visHistory.push(data.history_entry);}
                        }
                        renderVisibilityHistoryChart("b2sell_comp_visibility_trend_chart","b2sell_comp_visibility_trend_empty",b2sellCompPrimaryDomain);
                        renderVisibilityHistoryChart("b2sell_comp_visibility_history_chart","b2sell_comp_visibility_history_empty",b2sellCompPrimaryDomain);
                    }else{
                        $("#b2sell_comp_results").html("<div class=\\\"error\\\"><p>"+res.data+"</p></div>");
                    }
                },"json");
            });
        });
        </script>';
    }

    public function ajax_search() {
        check_ajax_referer( 'b2sell_competencia_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permisos insuficientes' );
        }
        $keywords     = isset( $_POST['keywords'] ) ? array_slice( array_filter( array_map( 'sanitize_text_field', (array) $_POST['keywords'] ) ), 0, 10 ) : array();
        $competitors  = isset( $_POST['competitors'] ) ? array_slice( array_filter( array_map( 'sanitize_text_field', (array) $_POST['competitors'] ) ), 0, 5 ) : array();
        $result_count = isset( $_POST['result_count'] ) ? intval( $_POST['result_count'] ) : 40;
        if ( ! in_array( $result_count, array( 10, 20, 40 ), true ) ) {
            $result_count = 40;
        }
        update_option( 'b2sell_comp_domains', $competitors );
        $api_key  = get_option( 'b2sell_google_api_key', '' );
        $cx       = get_option( 'b2sell_google_cx', '' );
        $serpapi  = get_option( 'b2sell_serpapi_key', '' );
        $provider = get_option( 'b2sell_comp_provider', 'google' );
        $gl       = get_option( 'b2sell_google_gl', 'cl' );
        $hl       = get_option( 'b2sell_google_hl', 'es' );
        $gl = $gl ? $gl : 'cl';
        $hl = $hl ? $hl : 'es';
        if ( empty( $keywords ) ) {
            wp_send_json_error( 'Palabras clave vacías' );
        }
        if ( ( 'google' === $provider && ( ! $api_key || ! $cx ) ) || ( 'serpapi' === $provider && ! $serpapi ) ) {
            wp_send_json_error( 'Proveedor de datos no configurado correctamente. Revisa la configuración.' );
        }
        $domain      = parse_url( home_url(), PHP_URL_HOST );
        $now         = current_time( 'mysql' );
        $analysis_date = $now;
        $query_plan  = $this->build_query_plan( $provider, $result_count );
        $volumes_data = $this->get_keyword_volumes( $keywords );
        $results     = array();
        $volumes_map = array();
        $visibility_domains = array_values( array_unique( array_merge( array( $domain ), $competitors ) ) );
        $visibility_totals  = array();
        foreach ( $visibility_domains as $vis_domain ) {
            $visibility_totals[ $vis_domain ] = 0;
        }
        $insert_ids = array();
        global $wpdb;
        foreach ( $keywords as $keyword ) {
            $volume = isset( $volumes_data[ $keyword ] ) ? (int) $volumes_data[ $keyword ] : 0;
            if ( ! $volume && ! empty( $volumes_data ) ) {
                $keyword_lower = strtolower( $keyword );
                foreach ( $volumes_data as $stored_keyword => $stored_volume ) {
                    if ( strtolower( (string) $stored_keyword ) === $keyword_lower ) {
                        $volume = (int) $stored_volume;
                        break;
                    }
                }
            }
            $cached = $this->maybe_get_cached_analysis( $keyword, $provider, $result_count, $competitors, $now );
            if ( $cached ) {
                $cached_volume = isset( $cached['volume'] ) ? (int) $cached['volume'] : 0;
                if ( $cached_volume > 0 ) {
                    $volume = $cached_volume;
                }
                $volumes_map[ $keyword ] = $volume;
                $results[ $keyword ]     = array(
                    'mine'        => $cached['mine'],
                    'competitors' => $cached['competitors'],
                    'volume'      => $volume,
                );
                $visibility_totals[ $domain ] += $volume * $this->ctr_from_rank( (int) $cached['mine'] );
                foreach ( $competitors as $c_domain ) {
                    if ( ! isset( $visibility_totals[ $c_domain ] ) ) {
                        $visibility_totals[ $c_domain ] = 0;
                    }
                    $rank = $cached['competitors'][ $c_domain ] ?? 0;
                    $visibility_totals[ $c_domain ] += $volume * $this->ctr_from_rank( (int) $rank );
                }
                continue;
            }
            $my_rank    = 0;
            $comp_ranks = array();
            foreach ( $competitors as $c ) {
                $comp_ranks[ $c ] = 0;
            }
            $found  = 0;
            $target = max( 1, count( $comp_ranks ) + 1 );
            foreach ( $query_plan as $query ) {
                if ( $found >= $target ) {
                    break;
                }
                if ( 'serpapi' === $provider ) {
                    $url      = add_query_arg(
                        array(
                            'engine' => 'google',
                            'api_key'=> $serpapi,
                            'q'      => $keyword,
                            'num'    => $query['num'],
                            'start'  => $query['start'],
                            'hl'     => $hl,
                            'gl'     => $gl,
                        ),
                        'https://serpapi.com/search.json'
                    );
                    $response = wp_remote_get( $url );
                    if ( is_wp_error( $response ) ) {
                        continue;
                    }
                    $data        = json_decode( wp_remote_retrieve_body( $response ), true );
                    $items       = $data['organic_results'] ?? array();
                    $rank_offset = $query['start'];
                    $rank_base   = 1;
                } else {
                    $url      = add_query_arg(
                        array(
                            'key'   => $api_key,
                            'cx'    => $cx,
                            'q'     => $keyword,
                            'num'   => $query['num'],
                            'start' => $query['start'],
                            'gl'    => $gl,
                            'hl'    => $hl,
                        ),
                        'https://www.googleapis.com/customsearch/v1'
                    );
                    $response = wp_remote_get( $url );
                    if ( is_wp_error( $response ) ) {
                        continue;
                    }
                    $data        = json_decode( wp_remote_retrieve_body( $response ), true );
                    $items       = $data['items'] ?? array();
                    $rank_offset = $query['start'];
                    $rank_base   = 0;
                }
                foreach ( $items as $index => $item ) {
                    $link        = $item['link'] ?? '';
                    $item_domain = parse_url( $link, PHP_URL_HOST );
                    $rank        = $rank_offset + $index + $rank_base;
                    if ( ! $my_rank && $item_domain === $domain ) {
                        $my_rank = $rank;
                        $found++;
                    }
                    foreach ( $comp_ranks as $cd => $r ) {
                        if ( ! $r && $item_domain === $cd ) {
                            $comp_ranks[ $cd ] = $rank;
                            $found++;
                        }
                    }
                }
            }
            $results[ $keyword ] = array(
                'mine'        => $my_rank,
                'competitors' => $comp_ranks,
                'volume'      => $volume,
            );
            $volumes_map[ $keyword ] = $volume;
            $visibility_totals[ $domain ] += $volume * $this->ctr_from_rank( (int) $my_rank );
            foreach ( $competitors as $c_domain ) {
                if ( ! isset( $visibility_totals[ $c_domain ] ) ) {
                    $visibility_totals[ $c_domain ] = 0;
                }
                $visibility_totals[ $c_domain ] += $volume * $this->ctr_from_rank( (int) ( $comp_ranks[ $c_domain ] ?? 0 ) );
            }
            $store = array();
            foreach ( $comp_ranks as $cd => $r ) {
                $store[] = array( 'domain' => $cd, 'rank' => $r );
            }
            $wpdb->insert(
                $this->table,
                array(
                    'date'     => $analysis_date,
                    'keyword'  => $keyword,
                    'keywords' => wp_json_encode(
                        array(
                            'keywords'     => $keywords,
                            'result_count' => $result_count,
                        )
                    ),
                    'post_id'  => 0,
                    'results'  => wp_json_encode( $store ),
                    'my_rank'  => $my_rank,
                    'provider' => $provider,
                )
            );
            if ( $wpdb->insert_id ) {
                $insert_ids[] = (int) $wpdb->insert_id;
            }
        }
        $raw_visibility = array();
        $max_visibility = 0.0;
        foreach ( $visibility_domains as $vis_domain ) {
            $value = isset( $visibility_totals[ $vis_domain ] ) ? (float) $visibility_totals[ $vis_domain ] : 0.0;
            $raw_visibility[ $vis_domain ] = round( $value, 2 );
            if ( $value > $max_visibility ) {
                $max_visibility = $value;
            }
        }
        $normalized_visibility = array();
        foreach ( $visibility_domains as $vis_domain ) {
            if ( $max_visibility > 0 ) {
                $normalized_visibility[ $vis_domain ] = round( ( $visibility_totals[ $vis_domain ] / $max_visibility ) * 100, 2 );
            } else {
                $normalized_visibility[ $vis_domain ] = 0.0;
            }
        }
        if ( $insert_ids ) {
            $meta_template = array(
                'keywords'     => $keywords,
                'result_count' => $result_count,
                'volumes'      => $volumes_map,
                'visibility'   => array(
                    'normalized' => $normalized_visibility,
                    'raw'        => $raw_visibility,
                ),
            );
            $meta_json = wp_json_encode( $meta_template );
            if ( false !== $meta_json ) {
                foreach ( $insert_ids as $insert_id ) {
                    $wpdb->update(
                        $this->table,
                        array( 'keywords' => $meta_json ),
                        array( 'id' => $insert_id ),
                        array( '%s' ),
                        array( '%d' )
                    );
                }
            }
        }
        $response = array(
            'domain'      => $domain,
            'competitors' => $competitors,
            'results'     => $results,
            'visibility'  => array(
                'raw'        => $raw_visibility,
                'normalized' => $normalized_visibility,
            ),
            'volumes'     => $volumes_map,
        );
        if ( $insert_ids ) {
            $response['history_entry'] = array(
                'date'       => $analysis_date,
                'visibility' => $normalized_visibility,
            );
        }
        $changes = $this->calculate_position_changes( $keywords, $provider, $results );
        $response['position_changes'] = $changes['items'];
        $response['position_summary'] = $changes['summary'];
        wp_send_json_success( $response );
    }
    public function ajax_history_detail() {
        check_ajax_referer( 'b2sell_competencia_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permisos insuficientes' );
        }
        $id = intval( $_POST['id'] ?? 0 );
        if ( ! $id ) {
            wp_send_json_error( 'ID inválido' );
        }
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id=%d", $id ) );
        if ( ! $row ) {
            wp_send_json_error( 'Registro no encontrado' );
        }
        $record = array(
            'date'    => $row->date,
            'keyword' => $row->keyword,
            'results' => json_decode( $row->results, true ),
            'post_id' => (int) $row->post_id,
            'my_rank' => (int) $row->my_rank,
            'provider' => $row->provider,
        );
        $history_rows = $wpdb->get_results( $wpdb->prepare( "SELECT date,my_rank,results FROM {$this->table} WHERE keyword=%s ORDER BY date ASC", $row->keyword ) );
        $labels       = array();
        $my_ranks     = array();
        $comp_domain  = '';
        $comp_ranks   = array();
        foreach ( $history_rows as $h ) {
            $labels[]   = $h->date;
            $my_ranks[] = (int) $h->my_rank;
            $items      = json_decode( $h->results, true );
            if ( ! $comp_domain && ! empty( $items ) ) {
                $comp_domain = $items[0]['domain'] ?? '';
            }
            $rank = null;
            if ( $comp_domain ) {
                foreach ( $items as $it ) {
                    if ( ( $it['domain'] ?? '' ) === $comp_domain ) {
                        $rank = (int) $it['rank'];
                        break;
                    }
                }
            }
            $comp_ranks[] = $rank;
        }
        wp_send_json_success(
            array(
                'record' => $record,
                'chart'  => array(
                    'labels'     => $labels,
                    'my'         => $my_ranks,
                    'competitor' => array(
                        'domain' => $comp_domain,
                        'ranks'  => $comp_ranks,
                    ),
                ),
            )
        );
    }

    public function ajax_reanalyze() {
        check_ajax_referer( 'b2sell_competencia_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permisos insuficientes' );
        }
        $id = intval( $_POST['id'] ?? 0 );
        if ( ! $id ) {
            wp_send_json_error( 'ID inválido' );
        }
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id=%d", $id ) );
        if ( ! $row ) {
            wp_send_json_error( 'Registro no encontrado' );
        }
        $keyword  = $row->keyword;
        $post_id  = (int) $row->post_id;
        $api_key  = get_option( 'b2sell_google_api_key', '' );
        $cx       = get_option( 'b2sell_google_cx', '' );
        $serpapi  = get_option( 'b2sell_serpapi_key', '' );
        $provider = get_option( 'b2sell_comp_provider', 'google' );
        $gl       = get_option( 'b2sell_google_gl', 'cl' );
        $hl       = get_option( 'b2sell_google_hl', 'es' );
        $gl       = $gl ? $gl : 'cl';
        $hl       = $hl ? $hl : 'es';
        if ( ( 'google' === $provider && ( ! $api_key || ! $cx ) ) || ( 'serpapi' === $provider && ! $serpapi ) ) {
            wp_send_json_error( 'Proveedor de datos no configurado' );
        }
        $kw_results = array();
        $my_rank    = 0;
        $my_url     = $post_id ? get_permalink( $post_id ) : '';
        $volumes    = $this->get_keyword_volumes( array( $keyword ) );
        $volume     = $volumes[ $keyword ] ?? 0;
        if ( 'serpapi' === $provider ) {
            for ( $page = 0; $page < 5; $page++ ) {
                $start    = $page * 10;
                $url      = add_query_arg(
                    array(
                        'engine' => 'google',
                        'api_key'=> $serpapi,
                        'q'      => $keyword,
                        'num'    => 1,
                        'start'  => $start,
                        'hl'     => $hl,
                        'gl'     => $gl,
                    ),
                    'https://serpapi.com/search.json'
                );
                $response = wp_remote_get( $url );
                if ( is_wp_error( $response ) ) {
                    continue;
                }
                $data = json_decode( wp_remote_retrieve_body( $response ), true );
                $item = $data['organic_results'][0] ?? null;
                if ( ! $item ) {
                    continue;
                }
                $link   = $item['link'] ?? '';
                $domain = parse_url( $link, PHP_URL_HOST );
                $rank   = $start + 1;
                if ( $my_url && trailingslashit( $link ) === trailingslashit( $my_url ) ) {
                    $my_rank = $rank;
                }
                $kw_results[] = array(
                    'title'   => $item['title'] ?? '',
                    'snippet' => $item['snippet'] ?? '',
                    'link'    => $link,
                    'domain'  => $domain,
                    'rank'    => $rank,
                    'traffic' => intval( $volume * $this->ctr_from_rank( $rank ) ),
                );
            }
        } else {
            $url      = add_query_arg(
                array(
                    'key' => $api_key,
                    'cx'  => $cx,
                    'q'   => $keyword,
                    'num' => 5,
                    'gl'  => $gl,
                    'hl'  => $hl,
                ),
                'https://www.googleapis.com/customsearch/v1'
            );
            $response = wp_remote_get( $url );
            if ( is_wp_error( $response ) ) {
                wp_send_json_error( 'Error en consulta' );
            }
            $data  = json_decode( wp_remote_retrieve_body( $response ), true );
            $items = $data['items'] ?? array();
            foreach ( $items as $index => $item ) {
                $link   = $item['link'] ?? '';
                $domain = parse_url( $link, PHP_URL_HOST );
                $rank   = $index + 1;
                if ( $my_url && trailingslashit( $link ) === trailingslashit( $my_url ) ) {
                    $my_rank = $rank;
                }
                $kw_results[] = array(
                    'title'   => $item['title'] ?? '',
                    'snippet' => $item['snippet'] ?? '',
                    'link'    => $link,
                    'domain'  => $domain,
                    'rank'    => $rank,
                    'traffic' => intval( $volume * $this->ctr_from_rank( $rank ) ),
                );
            }
        }
        $wpdb->insert(
            $this->table,
            array(
                'date'     => current_time( 'mysql' ),
                'keyword'  => $keyword,
                'keywords' => $row->keywords,
                'post_id'  => $post_id,
                'results'  => wp_json_encode( $kw_results ),
                'my_rank'  => $my_rank,
                'provider' => $provider,
            )
        );
        wp_send_json_success();
    }

    public function ajax_interpret() {
        check_ajax_referer( 'b2sell_competencia_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permisos insuficientes' );
        }
        $rank    = intval( $_POST['rank'] ?? 0 );
        $current = intval( $_POST['current'] ?? 0 );
        $pos3    = intval( $_POST['pos3'] ?? 0 );
        $pos2    = intval( $_POST['pos2'] ?? 0 );
        $pos1    = intval( $_POST['pos1'] ?? 0 );
        $keyword = sanitize_text_field( $_POST['keyword'] ?? '' );
        $api_key = get_option( 'b2sell_openai_api_key', '' );
        if ( ! $api_key ) {
            wp_send_json_error( 'API Key de OpenAI no configurada' );
        }
        $prompt = 'El sitio está en la posición ' . $rank . ' para la palabra clave "' . $keyword . '" con un tráfico estimado de '
            . $current . ' visitas mensuales. En la posición 3 tendría ' . $pos3 . ', en la posición 2 ' . $pos2 .
            ' y en la posición 1 ' . $pos1 .
            '. Redacta una breve interpretación en español sobre el potencial de mejora del tráfico mencionando incrementos porcentuales.';
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
            wp_send_json_error( 'Error de conexión con OpenAI' );
        }
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        $text = trim( $data['choices'][0]['message']['content'] ?? '' );
        if ( ! $text ) {
            wp_send_json_error( 'Respuesta inválida de OpenAI' );
        }
        wp_send_json_success( array( 'text' => $text ) );
    }

    public function ajax_optimize() {
        check_ajax_referer( 'b2sell_competencia_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permisos insuficientes' ) );
        }
        $post_id = intval( $_POST['post_id'] ?? 0 );
        $keyword = sanitize_text_field( $_POST['keyword'] ?? '' );
        $results = isset( $_POST['results'] ) && is_array( $_POST['results'] ) ? $_POST['results'] : array();
        if ( ! $post_id ) {
            wp_send_json_error( array( 'message' => 'Post inválido' ) );
        }
        $api_key = get_option( 'b2sell_openai_api_key', '' );
        if ( ! $api_key ) {
            wp_send_json_error( array( 'message' => 'API Key de OpenAI no configurada' ) );
        }
        $post = get_post( $post_id );
        $meta = get_post_meta( $post_id, '_b2sell_meta_description', true );
        if ( ! $meta ) {
            $meta = wp_trim_words( $post->post_content, 30 );
        }
        $competitors_text = '';
        foreach ( $results as $r ) {
            $competitors_text .= '- ' . ( $r['title'] ?? '' ) . ' : ' . ( $r['snippet'] ?? '' ) . "\n";
        }
        $prompt = 'Eres un experto en SEO. Analiza mi contenido y la competencia para la palabra clave "' . $keyword . '". Mi título: ' . $post->post_title . '. Mi meta description: ' . $meta . '. Competencia:\n' . $competitors_text . 'Devuelve un JSON con keys title, meta y keywords (lista de 3 palabras clave relacionadas).';
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
            wp_send_json_error( array( 'message' => $response->get_error_message() ) );
        }
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset( $data['error']['message'] ) ) {
            wp_send_json_error( array( 'message' => $data['error']['message'] ) );
        }
        $content = $data['choices'][0]['message']['content'] ?? '';
        $content = trim( preg_replace( '/```json|```/', '', $content ) );
        $json    = json_decode( $content, true );
        if ( ! $json ) {
            wp_send_json_error( array( 'message' => 'Respuesta inválida de OpenAI' ) );
        }
        wp_send_json_success( $json );
    }

    public function ajax_insert() {
        check_ajax_referer( 'b2sell_competencia_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permisos insuficientes' );
        }
        $post_id = intval( $_POST['post_id'] ?? 0 );
        $title   = sanitize_text_field( $_POST['title'] ?? '' );
        $meta    = sanitize_text_field( $_POST['meta'] ?? '' );
        if ( ! $post_id ) {
            wp_send_json_error( 'Post inválido' );
        }
        if ( $title ) {
            wp_update_post( array( 'ID' => $post_id, 'post_title' => $title ) );
        }
        if ( $meta ) {
            update_post_meta( $post_id, '_b2sell_meta_description', $meta );
        }
        wp_send_json_success();
    }
}