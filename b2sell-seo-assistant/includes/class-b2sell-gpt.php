<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class B2Sell_GPT_Generator {

    public function __construct() {
        add_action( 'wp_ajax_b2sell_gpt_generate', array( $this, 'ajax_generate' ) );
        add_action( 'wp_ajax_b2sell_gpt_insert', array( $this, 'ajax_insert' ) );
    }

    public function render_admin_page() {
        $posts = get_posts( array(
            'post_type'   => array( 'post', 'page' ),
            'post_status' => 'publish',
            'numberposts' => -1,
        ) );
        $nonce = wp_create_nonce( 'b2sell_gpt_nonce' );
        ?>
        <div class="wrap">
            <h1>Generador de Contenido (GPT)</h1>
            <p>Ingrese una palabra clave o seleccione un post/página existente.</p>
            <input type="text" id="b2sell_gpt_keyword" placeholder="Palabra clave" style="width:300px;" />
            <select id="b2sell_gpt_post">
                <option value="">Seleccione un post/página</option>
                <?php foreach ( $posts as $p ) : ?>
                    <option value="<?php echo esc_attr( $p->ID ); ?>"><?php echo esc_html( $p->post_title ); ?></option>
                <?php endforeach; ?>
            </select>
            <br/><br/>
            <textarea id="b2sell_gpt_paragraph" placeholder="Párrafo a reescribir" style="width:100%;height:100px;"></textarea>
            <br/><br/>
            <button class="button" id="b2sell_gpt_title_btn">Generar título optimizado para SEO</button>
            <button class="button" id="b2sell_gpt_meta_btn">Generar meta description optimizada</button>
            <button class="button" id="b2sell_gpt_rewrite_btn">Reescribir párrafo</button>
            <button class="button button-primary" id="b2sell_gpt_post_btn">Crear post (~600 palabras)</button>
            <hr/>
            <div id="b2sell_gpt_results" style="border:1px solid #ccc;padding:10px;display:none;"></div>
            <p style="font-size:12px;color:#666;margin-top:10px;">Desarrollado por B2Sell SPA.</p>
        </div>
        <script>
        const b2sell_gpt_nonce = '<?php echo esc_js( $nonce ); ?>';
        function b2sellGPTRequest(type){
            const keyword = document.getElementById('b2sell_gpt_keyword').value;
            const postId = document.getElementById('b2sell_gpt_post').value;
            const paragraph = document.getElementById('b2sell_gpt_paragraph').value;
            jQuery.post(ajaxurl,{action:'b2sell_gpt_generate',gpt_action:type,keyword:keyword,post_id:postId,paragraph:paragraph,_wpnonce:b2sell_gpt_nonce},function(res){
                if(res.success){
                    const r=document.getElementById('b2sell_gpt_results');
                    r.style.display='block';
                    let html='<h2>Contenido generado por B2SELL GPT Assistant</h2><pre>'+res.data.content+'</pre>';
                    html+='<button class="button" onclick="b2sellGPTCopy()">Copiar</button>';
                    if(postId){
                        html+=' <button class="button button-primary" onclick="b2sellGPTInsert(\''+type+'\',\''+postId+'\')">Insertar en post/página</button>';
                    }
                    r.innerHTML=html;
                }else{
                    alert(res.data);
                }
            });
        }
        function b2sellGPTCopy(){
            const t=document.querySelector('#b2sell_gpt_results pre').innerText;
            navigator.clipboard.writeText(t);
        }
        function b2sellGPTInsert(type,postId){
            const content=document.querySelector('#b2sell_gpt_results pre').innerText;
            jQuery.post(ajaxurl,{action:'b2sell_gpt_insert',gpt_action:type,post_id:postId,content:content,_wpnonce:b2sell_gpt_nonce},function(res){
                if(res.success){alert('Contenido insertado');}else{alert(res.data);}
            });
        }
        jQuery(function($){
            $('#b2sell_gpt_title_btn').on('click',function(){b2sellGPTRequest('title');});
            $('#b2sell_gpt_meta_btn').on('click',function(){b2sellGPTRequest('meta');});
            $('#b2sell_gpt_rewrite_btn').on('click',function(){b2sellGPTRequest('rewrite');});
            $('#b2sell_gpt_post_btn').on('click',function(){b2sellGPTRequest('post');});
        });
        </script>
        <?php
    }

    public function ajax_generate() {
        check_ajax_referer( 'b2sell_gpt_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permisos insuficientes' );
        }
        $action    = sanitize_text_field( $_POST['gpt_action'] ?? '' );
        $keyword   = sanitize_text_field( $_POST['keyword'] ?? '' );
        $paragraph = sanitize_textarea_field( $_POST['paragraph'] ?? '' );
        $api_key   = get_option( 'b2sell_openai_api_key', '' );
        if ( ! $api_key ) {
            wp_send_json_error( 'API Key no configurada' );
        }
        switch ( $action ) {
            case 'title':
                $prompt = 'Genera un título atractivo y optimizado para SEO sobre: ' . $keyword;
                break;
            case 'meta':
                $prompt = 'Escribe una meta descripción optimizada para SEO (máximo 155 caracteres) sobre: ' . $keyword;
                break;
            case 'rewrite':
                $prompt = 'Reescribe el siguiente párrafo mejorando el SEO y usando mejores palabras clave:\n\n' . $paragraph;
                break;
            case 'post':
                $prompt = 'Redacta un artículo de aproximadamente 600 palabras optimizado para SEO sobre: ' . $keyword;
                break;
            default:
                wp_send_json_error( 'Acción no válida' );
        }
        $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ),
            'body'    => wp_json_encode( array(
                'model'    => 'gpt-3.5-turbo',
                'messages' => array(
                    array( 'role' => 'user', 'content' => $prompt ),
                ),
            ) ),
        ) );
        if ( is_wp_error( $response ) ) {
            wp_send_json_error( $response->get_error_message() );
        }
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! isset( $data['choices'][0]['message']['content'] ) ) {
            wp_send_json_error( 'Respuesta inválida de OpenAI' );
        }
        $content = trim( $data['choices'][0]['message']['content'] );
        wp_send_json_success( array( 'content' => $content ) );
    }

    public function ajax_insert() {
        check_ajax_referer( 'b2sell_gpt_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permisos insuficientes' );
        }
        $action  = sanitize_text_field( $_POST['gpt_action'] ?? '' );
        $post_id = intval( $_POST['post_id'] ?? 0 );
        $content = wp_kses_post( $_POST['content'] ?? '' );
        if ( ! $post_id ) {
            wp_send_json_error( 'Post inválido' );
        }
        switch ( $action ) {
            case 'title':
                wp_update_post( array( 'ID' => $post_id, 'post_title' => $content ) );
                break;
            case 'meta':
                update_post_meta( $post_id, '_b2sell_meta_description', $content );
                break;
            case 'rewrite':
                $post = get_post( $post_id );
                wp_update_post( array( 'ID' => $post_id, 'post_content' => $post->post_content . "\n\n" . $content ) );
                break;
            case 'post':
                wp_update_post( array( 'ID' => $post_id, 'post_content' => $content ) );
                break;
            default:
                wp_send_json_error( 'Acción no válida' );
        }
        wp_send_json_success();
    }
}
