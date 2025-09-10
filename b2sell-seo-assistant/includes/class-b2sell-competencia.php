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

    private function ctr_from_rank( $rank ) {
        if ( 1 === $rank ) {
            return 0.27;
        }
        if ( 2 === $rank ) {
            return 0.15;
        }
        if ( 3 === $rank ) {
            return 0.10;
        }
        if ( in_array( $rank, array( 4, 5 ), true ) ) {
            return 0.06;
        }
        if ( in_array( $rank, array( 6, 7 ), true ) ) {
            return 0.04;
        }
        if ( $rank >= 8 && $rank <= 10 ) {
            return 0.02;
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
        global $wpdb;
        $history = $wpdb->get_results( "SELECT * FROM {$this->table} ORDER BY date DESC LIMIT 20" );
        echo '<div class="wrap">';
        if ( ( 'google' === $provider && ( ! $api_key || ! $cx ) ) || ( 'serpapi' === $provider && ! $serpapi_key ) ) {
            $link = esc_url( admin_url( 'admin.php?page=b2sell-seo-config' ) );
            $msg  = ( 'google' === $provider ) ? 'Google Custom Search no está configurado. Configura la API Key y el ID del motor de búsqueda (CX)' : 'SerpAPI no está configurado. Configura la API Key';
            echo '<div class="error"><p>' . $msg . ' en la <a href="' . $link . '">página de Configuración</a>.</p></div>';
        }
        echo '<h1>Competencia</h1>';
        echo '<h2 class="nav-tab-wrapper"><a href="#" class="nav-tab nav-tab-active" data-tab="analysis">Análisis</a><a href="#" class="nav-tab" data-tab="history">Histórico</a></h2>';

        echo '<div id="b2sell_comp_tab_analysis" class="b2sell-comp-tab">';
        echo '<p>Ingresa hasta 10 palabras clave (una por línea) y hasta 5 dominios competidores.</p>';
        echo '<textarea id="b2sell_comp_keywords" placeholder="Palabras clave" style="width:300px;height:100px;"></textarea> ';
        echo '<textarea id="b2sell_comp_domains" placeholder="competidor1.com\ncompetidor2.com" style="width:300px;height:100px;margin-left:20px;">' . esc_textarea( implode( "\n", $competitors ) ) . '</textarea> ';
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
        echo '</div>';

        echo '</div>';

        echo '<div id="b2sell_comp_modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);">';
        echo '<div style="background:#fff;padding:20px;max-width:600px;margin:50px auto;">';
        echo '<h2>Sugerencias GPT</h2><div id="b2sell_comp_suggestions"></div>';
        echo '<button class="button" id="b2sell_comp_copy">Copiar</button> <button class="button" id="b2sell_comp_export">Exportar CSV</button> <button class="button button-primary" id="b2sell_comp_insert">Insertar en el contenido</button> <button class="button" id="b2sell_comp_close">Cerrar</button>';
        echo '</div></div>';

        echo '<div id="b2sell_comp_hist_modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);">';
        echo '<div style="background:#fff;padding:20px;max-width:800px;margin:50px auto;"><div id="b2sell_comp_hist_modal_content"></div><button class="button" id="b2sell_comp_hist_close">Cerrar</button></div></div>';
        echo '<script>var b2sellCompNonce="' . esc_js( $nonce ) . '";</script>';
        echo '<script>
        jQuery(function($){
            $(".nav-tab-wrapper .nav-tab").on("click",function(e){e.preventDefault();var t=$(this).data("tab");$(".nav-tab").removeClass("nav-tab-active");$(this).addClass("nav-tab-active");$(".b2sell-comp-tab").hide();$("#b2sell_comp_tab_"+t).show();});
            $("#b2sell_comp_search_btn").on("click", function(){
                var kws = $("#b2sell_comp_keywords").val().split(/\\n+/)
                    .map(function(s){return $.trim(s);})
                    .filter(function(s){return s.length;})
                    .slice(0,10);
                var doms = $("#b2sell_comp_domains").val().split(/\\n+/)
                    .map(function(s){return $.trim(s);})
                    .filter(function(s){return s.length;})
                    .slice(0,5);
                if(!kws.length){return;}
                $("#b2sell_comp_results").html("Analizando...");
                $.post(ajaxurl,{action:"b2sell_competencia_search",keywords:kws,competitors:doms,_wpnonce:b2sellCompNonce},function(res){
                    if(res.success){
                        var data=res.data;
                        var comps=data.competitors;
                        var html="<table class=\\"widefat\\"><thead><tr><th>Keyword</th><th>"+data.domain+"</th>";
                        comps.forEach(function(c){html+="<th>"+c+"</th>";});
                        html+="</tr></thead><tbody>";
                        kws.forEach(function(kw){
                            var row=data.results[kw]||{};
                            html+="<tr><td>"+kw+"</td><td>"+(row.mine||"-")+"</td>";
                            comps.forEach(function(c){var r=row.competitors&&row.competitors[c]?row.competitors[c]:"-";html+="<td>"+r+"</td>";});
                            html+="</tr>";
                        });
                        html+="</tbody></table>";
                        $("#b2sell_comp_results").html(html);
                    }else{
                        $("#b2sell_comp_results").html("<div class=\\"error\\"><p>"+res.data+"</p></div>");
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
        $keywords    = isset( $_POST['keywords'] ) ? array_slice( array_filter( array_map( 'sanitize_text_field', (array) $_POST['keywords'] ) ), 0, 10 ) : array();
        $competitors = isset( $_POST['competitors'] ) ? array_slice( array_filter( array_map( 'sanitize_text_field', (array) $_POST['competitors'] ) ), 0, 5 ) : array();
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
        $domain  = parse_url( home_url(), PHP_URL_HOST );
        $results = array();
        foreach ( $keywords as $keyword ) {
            $my_rank   = 0;
            $comp_ranks = array();
            foreach ( $competitors as $c ) {
                $comp_ranks[ $c ] = 0;
            }
            $found = 0;
            for ( $page = 0; $page < 5 && $found < ( count( $comp_ranks ) + 1 ); $page++ ) {
                if ( 'serpapi' === $provider ) {
                    $url = add_query_arg( array(
                        'engine' => 'google',
                        'api_key'=> $serpapi,
                        'q'      => $keyword,
                        'num'    => 10,
                        'start'  => $page * 10,
                        'hl'     => $hl,
                        'gl'     => $gl,
                    ), 'https://serpapi.com/search.json' );
                    $response = wp_remote_get( $url );
                    if ( is_wp_error( $response ) ) { continue; }
                    $data  = json_decode( wp_remote_retrieve_body( $response ), true );
                    $items = $data['organic_results'] ?? array();
                } else {
                    $url = add_query_arg( array(
                        'key'   => $api_key,
                        'cx'    => $cx,
                        'q'     => $keyword,
                        'num'   => 10,
                        'start' => $page * 10 + 1,
                        'gl'    => $gl,
                        'hl'    => $hl,
                    ), 'https://www.googleapis.com/customsearch/v1' );
                    $response = wp_remote_get( $url );
                    if ( is_wp_error( $response ) ) { continue; }
                    $data  = json_decode( wp_remote_retrieve_body( $response ), true );
                    $items = $data['items'] ?? array();
                }
                foreach ( $items as $index => $item ) {
                    $link        = $item['link'] ?? '';
                    $item_domain = parse_url( $link, PHP_URL_HOST );
                    $rank        = $page * 10 + $index + 1;
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
            );
            $store = array();
            foreach ( $comp_ranks as $cd => $r ) {
                $store[] = array( 'domain' => $cd, 'rank' => $r );
            }
            global $wpdb;
            $wpdb->insert( $this->table, array(
                'date'     => current_time( 'mysql' ),
                'keyword'  => $keyword,
                'keywords' => wp_json_encode( $keywords ),
                'post_id'  => 0,
                'results'  => wp_json_encode( $store ),
                'my_rank'  => $my_rank,
                'provider' => $provider,
            ) );
        }
        wp_send_json_success( array(
            'domain'      => $domain,
            'competitors' => $competitors,
            'results'     => $results,
        ) );
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