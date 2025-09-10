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
        $api_key = get_option( 'b2sell_google_api_key', '' );
        $cx      = get_option( 'b2sell_google_cx', '' );
        global $wpdb;
        $history = $wpdb->get_results( "SELECT * FROM {$this->table} ORDER BY date DESC LIMIT 20" );
        echo '<div class="wrap">';
        if ( ! $api_key || ! $cx ) {
            $link = esc_url( admin_url( 'admin.php?page=b2sell-seo-config' ) );
            echo '<div class="error"><p>Google Custom Search no está configurado. Configura la API Key y el ID del motor de búsqueda (CX) en la <a href="' . $link . '">página de Configuración</a>.</p></div>';
        }
        echo '<h1>Competencia</h1>';
        echo '<h2 class="nav-tab-wrapper"><a href="#" class="nav-tab nav-tab-active" data-tab="analysis">Análisis</a><a href="#" class="nav-tab" data-tab="history">Histórico</a></h2>';

        echo '<div id="b2sell_comp_tab_analysis" class="b2sell-comp-tab">';
        echo '<p>Ingresa hasta 5 palabras clave (una por línea) y selecciona una página o post de tu sitio para comparar.</p>';
        echo '<textarea id="b2sell_comp_keywords" placeholder="Palabras clave" style="width:300px;height:100px;"></textarea> ';
        echo '<select id="b2sell_comp_post"><option value="">Selecciona un post/página</option>';
        foreach ( $posts as $p ) {
            echo '<option value="' . esc_attr( $p->ID ) . '">' . esc_html( $p->post_title ) . '</option>';
        }
        echo '</select> ';
        echo '<button class="button" id="b2sell_comp_search_btn">Buscar</button>';
        echo '<div id="b2sell_comp_results" style="margin-top:20px;"></div>';
        echo '</div>';

        echo '<div id="b2sell_comp_tab_history" class="b2sell-comp-tab" style="display:none;">';
        echo '<table class="widefat"><thead><tr><th>Fecha</th><th>Keyword</th><th>Ranking competidores</th><th>Acciones</th></tr></thead><tbody>';
        if ( $history ) {
            foreach ( $history as $row ) {
                $results   = json_decode( $row->results, true );
                $rank_html = '';
                if ( $results ) {
                    foreach ( $results as $res ) {
                        $rank_html .= $res['rank'] . '. ' . esc_html( $res['title'] ) . '<br>';
                    }
                }
                echo '<tr><td>' . esc_html( $row->date ) . '</td><td>' . esc_html( $row->keyword ) . '</td><td>' . $rank_html . '</td><td><button class="button b2sell_comp_detail" data-id="' . esc_attr( $row->id ) . '">Ver detalles</button> <button class="button b2sell_comp_rean" data-id="' . esc_attr( $row->id ) . '">Reanalizar</button></td></tr>';
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

        echo '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';
        echo '<script>var b2sellCompPosts=' . wp_json_encode( $posts_js ) . ';var b2sellCompNonce="' . esc_js( $nonce ) . '";var b2sellCompResults={};var b2sellCompFlows={};</script>';
        echo '<script>
        jQuery(function($){
            $(".nav-tab-wrapper .nav-tab").on("click",function(e){e.preventDefault();var t=$(this).data("tab");$(".nav-tab").removeClass("nav-tab-active");$(this).addClass("nav-tab-active");$(".b2sell-comp-tab").hide();$("#b2sell_comp_tab_"+t).show();});
            $("#b2sell_comp_search_btn").on("click", function(){
                var kws = $("#b2sell_comp_keywords").val().split(/\n+/)
                    .map(function(s){return $.trim(s);})
                    .filter(function(s){return s.length;})
                    .slice(0,5);
                var pid = $("#b2sell_comp_post").val();
                if(!kws.length){return;}
                $("#b2sell_comp_results").html("Buscando...");
                $.ajax({
                    url: ajaxurl,
                    method: "POST",
                    dataType: "json",
                    data: {action:"b2sell_competencia_search",keywords:kws,post_id:pid,_wpnonce:b2sellCompNonce}
                }).done(function(res){
                    if(res.success){
                        b2sellCompResults = {};
                        b2sellCompFlows = {};
                        var html = "";
                        kws.forEach(function(kw){
                            var dataKw = res.data[kw] || {};
                            var list = dataKw.items || [];
                            var volume = dataKw.volume || 0;
                            var volTxt = volume ? volume : \'N/A\';
                            b2sellCompResults[kw] = list;
                            var flow = dataKw.traffic_flow || null;
                            if(flow){ b2sellCompFlows[kw] = flow; }
                            html += "<div class=\"b2sell-comp-block\" data-key=\""+kw+"\"><h2>"+kw+"</h2>";
                            if(list.length){
                                html += "<table class=\\"widefat\\"><thead><tr><th>Competidor</th><th>Descripción</th><th>URL</th><th>Volumen de búsqueda</th><th>Tráfico estimado</th></tr></thead><tbody>";
                                list.forEach(function(r){
                                    var trafTxt = volume ? r.traffic : \'N/A\';
                                    html += "<tr><td>"+r.title+"</td><td>"+r.snippet+"</td><td><a href=\\""+r.link+"\\" target=\\"_blank\\">"+r.link+"</a></td><td>"+volTxt+"</td><td>"+trafTxt+"</td></tr>";
                                });
                                html += "</tbody></table>";
                                if(pid){html += "<button class=\\"button b2sell_comp_opt_btn\\" data-keyword=\\""+kw+"\\" style=\\"margin-top:10px;\\">Optimizar con GPT</button>";}
                            }else{
                                html += "<p>No se encontraron resultados para esta keyword</p>";
                            }
                            if(flow){
                                html += "<canvas class=\"b2sell-comp-flow\" data-key=\""+kw+"\" height=\"100\" style=\"margin-top:20px\"></canvas><div class=\"b2sell-comp-interpret\" data-key=\""+kw+"\" style=\"margin-top:10px\"></div>";
                            }
                            html += "</div>";
                        });
                        $("#b2sell_comp_results").html(html);
                        Object.keys(b2sellCompFlows).forEach(function(key){
                            var flow=b2sellCompFlows[key];
                            var $canvas=$("canvas.b2sell-comp-flow[data-key=\'"+key+"\']");
                            if($canvas.length){
                                var ctx=$canvas[0].getContext("2d");
                                new Chart(ctx,{type:"bar",data:{labels:["Actual","3","2","1"],datasets:[{label:"Tráfico estimado",data:[flow.current,flow.pos3,flow.pos2,flow.pos1],backgroundColor:["#f44336","#ffeb3b","#ffeb3b","#4caf50"]}]},options:{scales:{y:{beginAtZero:true}}}});
                                var $div=$(".b2sell-comp-interpret[data-key=\'"+key+"\']");
                                $.post(ajaxurl,{action:"b2sell_competencia_interpret",rank:flow.current_rank,current:flow.current,pos3:flow.pos3,pos2:flow.pos2,pos1:flow.pos1,keyword:key,_wpnonce:b2sellCompNonce},function(resp){
                                    if(resp.success){$div.text(resp.data.text);}else{$div.text(resp.data);} });
                            }
                        });
                    }else{
                        $("#b2sell_comp_results").html("<div class=\"error\"><p>"+res.data+"</p></div>");
                    }
                }).fail(function(jqXHR){
                    var msg = jqXHR.responseText || "Error al realizar la búsqueda";
                    $("#b2sell_comp_results").html("<div class=\"error\"><p>"+msg+"</p></div>");
                });
            });
            $(document).on("click",".b2sell_comp_opt_btn",function(){
                var pid = $("#b2sell_comp_post").val();
                if(!pid){return;}
                var kw = $(this).data("keyword");
                var btn = $(this);
                btn.prop("disabled",true).text("Generando...");
                $.post(ajaxurl,{action:"b2sell_competencia_optimize",post_id:pid,keyword:kw,results:b2sellCompResults[kw]||[],_wpnonce:b2sellCompNonce},function(res){
                    btn.prop("disabled",false).text("Optimizar con GPT");
                    if(res.success){
                        var s=res.data;
                        $("#b2sell_comp_suggestions").html("<h3>Título sugerido</h3><p>"+s.title+"</p><h3>Meta description sugerida</h3><p>"+s.meta+"</p><h3>Keywords relacionadas</h3><p>"+s.keywords.join(", ")+"</p>");
                        $("#b2sell_comp_insert").data("post",pid).data("title",s.title).data("meta",s.meta).data("keyword",kw).data("suggestions",s);
                        $("#b2sell_comp_modal").show();
                    }else{
                        alert(res.data && res.data.message?res.data.message:res.data);
                    }
                });
            });
            $("#b2sell_comp_close").on("click",function(){$("#b2sell_comp_modal").hide();});
            $("#b2sell_comp_copy").on("click",function(){var t=$("#b2sell_comp_suggestions").text();navigator.clipboard.writeText(t);});
            $("#b2sell_comp_export").on("click",function(){var s=$("#b2sell_comp_insert").data("suggestions")||{};var kw=$("#b2sell_comp_insert").data("keyword")||"";var csv="Keyword,Título,Meta description,Keywords relacionadas\n";var row=[kw,s.title||"",s.meta||"",(s.keywords||[]).join(" ")];for(var i=0;i<row.length;i++){row[i]="\""+String(row[i]).replace(/"/g,"\"\"")+"\"";}csv+=row.join(",")+"\n";var blob=new Blob([csv],{type:"text/csv"});var a=document.createElement("a");a.href=URL.createObjectURL(blob);a.download="gpt_sugerencias_"+kw+".csv";a.click();});
            $("#b2sell_comp_insert").on("click",function(){var pid=$(this).data("post"),title=$(this).data("title"),meta=$(this).data("meta");$.post(ajaxurl,{action:"b2sell_competencia_insert",post_id:pid,title:title,meta:meta,_wpnonce:b2sellCompNonce},function(){alert("Insertado" );});});
            $(document).on("click",".b2sell_comp_detail",function(){
                var id=$(this).data("id");
                $.post(ajaxurl,{action:"b2sell_competencia_history_detail",id:id,_wpnonce:b2sellCompNonce},function(res){
                    if(res.success){
                        var r=res.data.record;
                        var html="<h2>"+r.keyword+" - "+r.date+"</h2>";
                        html+="<table class=\\"widefat\\"><thead><tr><th>Posición</th><th>Título</th><th>Meta</th><th>URL</th></tr></thead><tbody>";
                        r.results.forEach(function(it){html+="<tr><td>"+it.rank+"</td><td>"+it.title+"</td><td>"+it.snippet+"</td><td><a href=\\""+it.link+"\\" target=\\"_blank\\">"+it.link+"</a></td></tr>";});
                        html+="</tbody></table><canvas id=\\"b2sell_comp_chart\\" height=\\"100\\" style=\\"margin-top:20px\\"></canvas>";
                        $("#b2sell_comp_hist_modal_content").html(html);
                        $("#b2sell_comp_hist_modal").show();
                        if(res.data.chart){
                            var ctx=document.getElementById("b2sell_comp_chart").getContext("2d");
                            var datasets=[{label:"Mi sitio",data:res.data.chart.my,borderColor:"#0073aa",fill:false}];
                            if(res.data.chart.competitor&&res.data.chart.competitor.domain){datasets.push({label:res.data.chart.competitor.domain,data:res.data.chart.competitor.ranks,borderColor:"#cc0000",fill:false});}
                            new Chart(ctx,{type:"line",data:{labels:res.data.chart.labels,datasets:datasets},options:{scales:{y:{reverse:true,beginAtZero:true}}}});
                        }
                    }else{alert(res.data);}
                });
            });
            $("#b2sell_comp_hist_close").on("click",function(){$("#b2sell_comp_hist_modal").hide();});
            $(document).on("click",".b2sell_comp_rean",function(){var id=$(this).data("id");if(!confirm("Reanalizar esta keyword?")){return;}$.post(ajaxurl,{action:"b2sell_competencia_reanalyze",id:id,_wpnonce:b2sellCompNonce},function(res){if(res.success){location.reload();}else{alert(res.data);}});});
        });
        </script>';
    }

    public function ajax_search() {
        check_ajax_referer( 'b2sell_competencia_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permisos insuficientes' );
        }
        $keywords = isset( $_POST['keywords'] ) ? array_slice( array_filter( array_map( 'sanitize_text_field', (array) $_POST['keywords'] ) ), 0, 5 ) : array();
        $post_id  = intval( $_POST['post_id'] ?? 0 );
        $api_key  = get_option( 'b2sell_google_api_key', '' );
        $cx       = get_option( 'b2sell_google_cx', '' );
        $gl       = get_option( 'b2sell_google_gl', 'cl' );
        $hl       = get_option( 'b2sell_google_hl', 'es' );
        $gl       = $gl ? $gl : 'cl';
        $hl       = $hl ? $hl : 'es';
        if ( empty( $keywords ) ) {
            wp_send_json_error( 'Palabras clave vacías' );
        }
        if ( ! $api_key || ! $cx ) {
            wp_send_json_error( 'API Key o CX no configurados. Configura los valores en la página de Configuración.' );
        }
        $results = array();
        $my_url  = $post_id ? get_permalink( $post_id ) : '';
        $volumes = $this->get_keyword_volumes( $keywords );
        foreach ( $keywords as $keyword ) {
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
                wp_send_json_error( $response->get_error_message() );
            }
            $data       = json_decode( wp_remote_retrieve_body( $response ), true );
            $items      = $data['items'] ?? array();
            $kw_results = array();
            $my_rank    = 0;
            $volume     = $volumes[ $keyword ] ?? 0;
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
            $results[ $keyword ] = array(
                'items'      => $kw_results,
                'my_traffic' => intval( $volume * $this->ctr_from_rank( $my_rank ) ),
                'volume'     => $volume,
                'traffic_flow' => array(
                    'current_rank' => $my_rank,
                    'current'      => intval( $volume * $this->ctr_from_rank( $my_rank ) ),
                    'pos3'         => intval( $volume * $this->ctr_from_rank( 3 ) ),
                    'pos2'         => intval( $volume * $this->ctr_from_rank( 2 ) ),
                    'pos1'         => intval( $volume * $this->ctr_from_rank( 1 ) ),
                ),
            );
            global $wpdb;
            $wpdb->insert(
                $this->table,
                array(
                    'date'     => current_time( 'mysql' ),
                    'keyword'  => $keyword,
                    'keywords' => wp_json_encode( $keywords ),
                    'post_id'  => $post_id,
                    'results'  => wp_json_encode( $kw_results ),
                    'my_rank'  => $my_rank,
                )
            );
        }
        if ( empty( $results ) ) {
            wp_send_json_error( 'Sin resultados' );
        }
        wp_send_json_success( $results );
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
        $keyword = $row->keyword;
        $post_id = (int) $row->post_id;
        $api_key = get_option( 'b2sell_google_api_key', '' );
        $cx      = get_option( 'b2sell_google_cx', '' );
        $gl      = get_option( 'b2sell_google_gl', 'cl' );
        $hl      = get_option( 'b2sell_google_hl', 'es' );
        $gl      = $gl ? $gl : 'cl';
        $hl      = $hl ? $hl : 'es';
        if ( ! $api_key || ! $cx ) {
            wp_send_json_error( 'API Key o CX no configurados' );
        }
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
        $data      = json_decode( wp_remote_retrieve_body( $response ), true );
        $items     = $data['items'] ?? array();
        $kw_results = array();
        $my_rank    = 0;
        $my_url     = $post_id ? get_permalink( $post_id ) : '';
        $volumes    = $this->get_keyword_volumes( array( $keyword ) );
        $volume     = $volumes[ $keyword ] ?? 0;
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
        $wpdb->insert(
            $this->table,
            array(
                'date'     => current_time( 'mysql' ),
                'keyword'  => $keyword,
                'keywords' => $row->keywords,
                'post_id'  => $post_id,
                'results'  => wp_json_encode( $kw_results ),
                'my_rank'  => $my_rank,
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