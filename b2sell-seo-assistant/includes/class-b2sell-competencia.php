<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class B2Sell_Competencia {

    public function __construct() {
        add_action( 'wp_ajax_b2sell_competencia_search', array( $this, 'ajax_search' ) );
        add_action( 'wp_ajax_b2sell_competencia_optimize', array( $this, 'ajax_optimize' ) );
        add_action( 'wp_ajax_b2sell_competencia_insert', array( $this, 'ajax_insert' ) );
    }

    public function render_admin_page() {
        $posts = get_posts( array(
            'post_type'   => array( 'post', 'page' ),
            'post_status' => 'publish',
            'numberposts' => -1,
        ) );
        $nonce     = wp_create_nonce( 'b2sell_competencia_nonce' );
        $posts_js  = array();
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
        echo '<div class="wrap">';
        echo '<h1>Competencia</h1>';
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
        echo '<div id="b2sell_comp_modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);">';
        echo '<div style="background:#fff;padding:20px;max-width:600px;margin:50px auto;">';
        echo '<h2>Sugerencias GPT</h2><div id="b2sell_comp_suggestions"></div>';
        echo '<button class="button" id="b2sell_comp_copy">Copiar</button> <button class="button" id="b2sell_comp_export">Exportar CSV</button> <button class="button button-primary" id="b2sell_comp_insert">Insertar en el contenido</button> <button class="button" id="b2sell_comp_close">Cerrar</button>';
        echo '</div></div>';
        echo '<script>var b2sellCompPosts=' . wp_json_encode( $posts_js ) . ';var b2sellCompNonce="' . esc_js( $nonce ) . '";var b2sellCompResults={};</script>';
        echo '<script>
        jQuery(function($){
            $("#b2sell_comp_search_btn").on("click", function(){
                var kws = $("#b2sell_comp_keywords").val().split(/\n+/)
                    .map(function(s){return $.trim(s);})
                    .filter(function(s){return s.length;})
                    .slice(0,5);
                var pid = $("#b2sell_comp_post").val();
                if(!kws.length){return;}
                $("#b2sell_comp_results").html("Buscando...");
                $.post(ajaxurl,{action:"b2sell_competencia_search",keywords:kws,_wpnonce:b2sellCompNonce},function(res){
                    if(res.success){
                        b2sellCompResults = res.data;
                        var my = b2sellCompPosts[pid]||{title:"",meta:"",url:""};
                        var html = "";
                        kws.forEach(function(kw){
                            var list = res.data[kw] || [];
                            html += "<div class=\"b2sell-comp-block\" data-key=\""+kw+"\"><h2>"+kw+"</h2>";
                            if(list.length){
                                html += "<table class=\"widefat\"><thead><tr><th>Título</th><th>Meta description</th><th>URL</th><th>Mi título</th><th>Mi meta</th><th>Mi URL</th></tr></thead><tbody>";
                                list.forEach(function(r){
                                    html += "<tr><td>"+r.title+"</td><td>"+r.snippet+"</td><td><a href=\""+r.link+"\" target=\"_blank\">"+r.link+"</a></td><td>"+my.title+"</td><td>"+my.meta+"</td><td>"+(my.url?"<a href=\\\""+my.url+"\\\" target=\\\"_blank\\\">"+my.url+"</a>":"")+"</td></tr>";
                                });
                                html += "</tbody></table>";
                                if(pid){html += "<button class=\"button b2sell_comp_opt_btn\" data-keyword=\""+kw+"\" style=\"margin-top:10px;\">Optimizar con GPT</button>";}
                            }else{
                                html += "<p>Sin resultados</p>";
                            }
                            html += "</div>";
                        });
                        $("#b2sell_comp_results").html(html);
                    }else{
                        $("#b2sell_comp_results").html("<div class=\"error\"><p>"+res.data+"</p></div>");
                    }
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
        });
        </script>';
    }

    public function ajax_search() {
        check_ajax_referer( 'b2sell_competencia_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permisos insuficientes' );
        }
        $keywords = isset( $_POST['keywords'] ) ? array_slice( array_filter( array_map( 'sanitize_text_field', (array) $_POST['keywords'] ) ), 0, 5 ) : array();
        $api_key  = get_option( 'b2sell_google_api_key', '' );
        $cx       = get_option( 'b2sell_google_cx', '' );
        if ( empty( $keywords ) ) {
            wp_send_json_error( 'Palabras clave vacías' );
        }
        if ( ! $api_key || ! $cx ) {
            wp_send_json_error( 'API Key o CX no configurados' );
        }
        $results = array();
        foreach ( $keywords as $keyword ) {
            $url      = add_query_arg(
                array(
                    'key' => $api_key,
                    'cx'  => $cx,
                    'q'   => $keyword,
                    'num' => 5,
                ),
                'https://www.googleapis.com/customsearch/v1'
            );
            $response = wp_remote_get( $url );
            if ( is_wp_error( $response ) ) {
                $results[ $keyword ] = array();
                continue;
            }
            $data  = json_decode( wp_remote_retrieve_body( $response ), true );
            $items = $data['items'] ?? array();
            $kw_results = array();
            foreach ( $items as $item ) {
                $kw_results[] = array(
                    'title'   => $item['title'] ?? '',
                    'snippet' => $item['snippet'] ?? '',
                    'link'    => $item['link'] ?? '',
                );
            }
            $results[ $keyword ] = $kw_results;
        }
        if ( empty( $results ) ) {
            wp_send_json_error( 'Sin resultados' );
        }
        wp_send_json_success( $results );
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
