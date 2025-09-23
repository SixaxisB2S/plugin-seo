<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class B2Sell_GPT_Generator {

    public function __construct() {
        add_action( 'wp_ajax_b2sell_gpt_generate', array( $this, 'ajax_generate' ) );
        add_action( 'wp_ajax_b2sell_gpt_insert', array( $this, 'ajax_insert' ) );
        add_action( 'wp_ajax_b2sell_generate_meta', array( $this, 'ajax_generate_meta' ) );
        add_action( 'wp_ajax_b2sell_gpt_generate_blog', array( $this, 'ajax_generate_blog' ) );
        add_action( 'wp_ajax_b2sell_gpt_save_blog', array( $this, 'ajax_save_blog_post' ) );
        add_action( 'wp_ajax_crear_blog_seo', array( $this, 'ajax_crear_blog_seo' ) );
        add_action( 'wp_ajax_nopriv_crear_blog_seo', array( $this, 'ajax_crear_blog_seo' ) );
    }

    public function render_admin_page() {
        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'quick';
        if ( ! in_array( $tab, array( 'quick', 'blog' ), true ) ) {
            $tab = 'quick';
        }

        $nonce      = wp_create_nonce( 'b2sell_gpt_nonce' );
        $blog_nonce = wp_create_nonce( 'b2sell_gpt_blog' );

        $posts = get_posts( array(
            'post_type'   => array( 'post', 'page' ),
            'post_status' => 'publish',
            'numberposts' => -1,
        ) );

        $pages = array();
        if ( 'blog' === $tab ) {
            $pages = get_pages(
                array(
                    'sort_column' => 'post_title',
                    'sort_order'  => 'ASC',
                )
            );
        }

        $quick_url = esc_url( add_query_arg( array( 'page' => 'b2sell-seo-gpt', 'tab' => 'quick' ), admin_url( 'admin.php' ) ) );
        $blog_url  = esc_url( add_query_arg( array( 'page' => 'b2sell-seo-gpt', 'tab' => 'blog' ), admin_url( 'admin.php' ) ) );
        ?>
        <div class="wrap">
            <h1>Generador de Contenido (GPT)</h1>
            <h2 class="nav-tab-wrapper">
                <a href="<?php echo $quick_url; ?>" class="nav-tab <?php echo 'quick' === $tab ? 'nav-tab-active' : ''; ?>">Generador rápido</a>
                <a href="<?php echo $blog_url; ?>" class="nav-tab <?php echo 'blog' === $tab ? 'nav-tab-active' : ''; ?>">Crear Blog SEO</a>
            </h2>
            <?php if ( 'blog' === $tab ) : ?>
                <div class="crear-blog-form">
                    <form id="b2sell-blog-form" class="crear-blog-form__form">
                        <div class="crear-blog-form__group">
                            <label for="b2sell-blog-post-topic">Tema central del post</label>
                            <textarea id="b2sell-blog-post-topic" name="post_topic" required></textarea>
                        </div>
                        <div class="crear-blog-form__group">
                            <label for="b2sell-blog-keywords">Palabras clave (separadas por coma)</label>
                            <input type="text" id="b2sell-blog-keywords" name="keywords" class="regular-text" required />
                        </div>
                        <div class="crear-blog-form__group">
                            <label for="b2sell-blog-word-count">Cantidad de palabras</label>
                            <input type="number" id="b2sell-blog-word-count" name="word_count" value="800" min="100" step="50" />
                        </div>
                        <div class="crear-blog-form__group">
                            <label for="b2sell-blog-image-url">URL de la imagen destacada</label>
                            <input type="url" id="b2sell-blog-image-url" name="image_url" class="regular-text" required />
                        </div>
                        <div class="crear-blog-form__group">
                            <label for="b2sell-blog-cta-text">Texto del call to action</label>
                            <input type="text" id="b2sell-blog-cta-text" name="cta_text" class="regular-text" required />
                        </div>
                        <div class="crear-blog-form__group">
                            <label for="b2sell-blog-cta-page">Página para el CTA</label>
                            <select id="b2sell-blog-cta-page" name="cta_page" required>
                                <option value="">Selecciona una página</option>
                                <?php foreach ( $pages as $page ) : ?>
                                    <option value="<?php echo esc_attr( $page->ID ); ?>"><?php echo esc_html( $page->post_title ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="crear-blog-form__actions">
                            <button type="submit" class="button button-primary crear-blog-form__submit">Generar con GPT</button>
                        </div>
                    </form>
                </div>
                <div id="blog-preview" class="crear-blog-preview" style="display:none;"></div>
            <?php else : ?>
                <div class="b2sell-card">
                    <p>Ingrese una palabra clave o seleccione un post/página existente.</p>
                    <p>
                        <label for="b2sell_gpt_keyword" class="screen-reader-text">Palabra clave</label>
                        <input type="text" id="b2sell_gpt_keyword" placeholder="Palabra clave" style="width:300px;" />
                        <select id="b2sell_gpt_post">
                            <option value="">Seleccione un post/página</option>
                            <?php foreach ( $posts as $p ) : ?>
                                <option value="<?php echo esc_attr( $p->ID ); ?>"><?php echo esc_html( $p->post_title ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                    <p>
                        <label for="b2sell_gpt_paragraph" class="screen-reader-text">Párrafo a reescribir</label>
                        <textarea id="b2sell_gpt_paragraph" placeholder="Párrafo a reescribir" style="width:100%;height:100px;"></textarea>
                    </p>
                    <p>
                        <button class="button" id="b2sell_gpt_title_btn">Generar título optimizado para SEO</button>
                        <button class="button" id="b2sell_gpt_meta_btn">Generar meta description optimizada</button>
                        <button class="button" id="b2sell_gpt_rewrite_btn">Reescribir párrafo</button>
                        <button class="button button-primary" id="b2sell_gpt_post_btn">Crear post (~600 palabras)</button>
                    </p>
                </div>
                <div id="b2sell_gpt_results" class="b2sell-card" style="display:none;"></div>
            <?php endif; ?>
        </div>
        <script>
        const b2sell_gpt_nonce = '<?php echo esc_js( $nonce ); ?>';
        const b2sellBlogNonce = '<?php echo esc_js( $blog_nonce ); ?>';
        (function($){
            function b2sellGPTRequest(type){
                const keyword = $('#b2sell_gpt_keyword').val();
                const postId = $('#b2sell_gpt_post').val();
                const paragraph = $('#b2sell_gpt_paragraph').val();
                const results = $('#b2sell_gpt_results');
                if (!results.length) {
                    return;
                }
                $.post(ajaxurl,{action:'b2sell_gpt_generate',gpt_action:type,keyword:keyword,post_id:postId,paragraph:paragraph,_wpnonce:b2sell_gpt_nonce},function(res){
                    results.show();
                    if(res.success){
                        let html='<h2>Contenido generado por B2SELL GPT Assistant</h2><pre>'+res.data.content+'</pre>';
                        html+='<button class="button" onclick="b2sellGPTCopy()">Copiar</button>';
                        if(postId){
                            html+=' <button class="button button-primary" onclick="b2sellGPTInsert(\''+type+'\',\''+postId+'\')">Insertar en post/página</button>';
                        }
                        results.html(html);
                    }else{
                        const msg = res.data && res.data.message ? res.data.message : res.data;
                        results.html('<div class="b2sell-red" style="padding:10px;">'+msg+'</div>');
                    }
                });
            }
            window.b2sellGPTCopy = function(){
                const pre = $('#b2sell_gpt_results pre');
                if(!pre.length){return;}
                const text = pre.text();
                navigator.clipboard.writeText(text);
            };
            window.b2sellGPTInsert = function(type,postId){
                const pre = $('#b2sell_gpt_results pre');
                if(!pre.length){return;}
                const content = pre.text();
                $.post(ajaxurl,{action:'b2sell_gpt_insert',gpt_action:type,post_id:postId,content:content,_wpnonce:b2sell_gpt_nonce},function(res){
                    if(res.success){alert('Contenido insertado');}else{alert(res.data && res.data.message ? res.data.message : res.data);}
                });
            };
            function b2sellBlogSave(actionType, content){
                const preview = $('#blog-preview');
                if(!preview.length){return;}
                let notice = preview.find('.crear-blog-feedback');
                if(!notice.length){
                    notice = $('<div class="crear-blog-feedback"></div>');
                    preview.append(notice);
                }
                notice.removeClass('is-success is-error is-loading').addClass('is-loading').text('Guardando...');
                const temp=document.createElement('div');
                temp.innerHTML=content;
                const h1=temp.querySelector('h1');
                const title=h1?h1.textContent.trim():'';
                const payload={
                    action:'crear_blog_seo',
                    post_action:actionType,
                    estado:actionType,
                    content:content,
                    title:title,
                    post_topic:$('#b2sell-blog-post-topic').val(),
                    image_url:$('#b2sell-blog-image-url').val(),
                    cta_text:$('#b2sell-blog-cta-text').val(),
                    cta_page:$('#b2sell-blog-cta-page').val(),
                    _wpnonce:b2sellBlogNonce
                };
                $.post(ajaxurl,payload,function(res){
                    if(res.success){
                        let html='<strong>'+res.data.message+'</strong>';
                        if(res.data.post_id){
                            html+=' <span>ID: '+res.data.post_id+'</span>';
                        }
                        if(res.data.edit_link){
                            html+=' <a href="'+res.data.edit_link+'" target="_blank" rel="noopener noreferrer">Editar</a>';
                        }
                        if(actionType==='publicar' && res.data.view_link){
                            html+=' <a href="'+res.data.view_link+'" target="_blank" rel="noopener noreferrer">Ver</a>';
                        }
                        notice.removeClass('is-loading is-error').addClass('is-success').html(html);
                    }else{
                        const msg = res.data && res.data.message ? res.data.message : res.data;
                        notice.removeClass('is-loading is-success').addClass('is-error').text(msg);
                    }
                });
            }
            $(function(){
                if($('#b2sell_gpt_results').length){
                    $('#b2sell_gpt_title_btn').on('click',function(){b2sellGPTRequest('title');});
                    $('#b2sell_gpt_meta_btn').on('click',function(){b2sellGPTRequest('meta');});
                    $('#b2sell_gpt_rewrite_btn').on('click',function(){b2sellGPTRequest('rewrite');});
                    $('#b2sell_gpt_post_btn').on('click',function(){b2sellGPTRequest('post');});
                }
                const blogForm = $('#b2sell-blog-form');
                if(blogForm.length){
                    let blogContent='';
                    blogForm.on('submit',function(e){
                        e.preventDefault();
                        const data={action:'b2sell_gpt_generate_blog',_wpnonce:b2sellBlogNonce,post_topic:$('#b2sell-blog-post-topic').val(),keywords:$('#b2sell-blog-keywords').val(),word_count:$('#b2sell-blog-word-count').val(),image_url:$('#b2sell-blog-image-url').val(),cta_text:$('#b2sell-blog-cta-text').val(),cta_page:$('#b2sell-blog-cta-page').val()};
                        const preview=$('#blog-preview');
                        preview.show().html('<div class="crear-blog-preview__loading">Generando contenido...</div>');
                        preview.find('.crear-blog-feedback').remove();
                        $.post(ajaxurl,data,function(res){
                            if(res.success){
                                blogContent=res.data.content;
                                let html='<div class="crear-blog-preview__header"><h2>Vista previa del blog</h2></div>';
                                html+='<div class="crear-blog-preview__content">'+res.data.content+'</div>';
                                html+='<div class="crear-blog-preview__actions">';
                                html+='<button type="button" class="crear-blog-secondary-btn" id="b2sell-blog-save-draft">Guardar como borrador</button>';
                                html+='<button type="button" class="crear-blog-secondary-btn" id="b2sell-blog-publish">Publicar ahora</button>';
                                html+='<button type="button" class="crear-blog-secondary-btn" id="b2sell-blog-download">Descargar texto</button>';
                                html+='</div>';
                                preview.html(html);
                            }else{
                                blogContent='';
                                const msg=res.data && res.data.message ? res.data.message : res.data;
                                preview.html('<div class="crear-blog-preview__error">'+msg+'</div>');
                            }
                        });
                    });
                    $('#blog-preview').on('click','#b2sell-blog-save-draft',function(){if(!blogContent){return;}b2sellBlogSave('guardar',blogContent);});
                    $('#blog-preview').on('click','#b2sell-blog-publish',function(){if(!blogContent){return;}b2sellBlogSave('publicar',blogContent);});
                    $('#blog-preview').on('click','#b2sell-blog-download',function(){if(!blogContent){return;}const temp=document.createElement('div');temp.innerHTML=blogContent;const text=temp.textContent||temp.innerText||'';const blob=new Blob([text],{type:'text/plain;charset=utf-8'});const url=URL.createObjectURL(blob);const a=document.createElement('a');a.href=url;a.download='blog-gpt.txt';document.body.appendChild(a);a.click();document.body.removeChild(a);URL.revokeObjectURL(url);});
                }
            });
        })(jQuery);
        </script>
        <?php
    }

    public function ajax_generate() {
        check_ajax_referer( 'b2sell_gpt_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permisos insuficientes' ) );
        }
        $action    = sanitize_text_field( $_POST['gpt_action'] ?? '' );
        $keyword   = sanitize_text_field( $_POST['keyword'] ?? '' );
        $paragraph = sanitize_textarea_field( $_POST['paragraph'] ?? '' );
        $post_id   = intval( $_POST['post_id'] ?? 0 );
        if ( ! $keyword && $post_id ) {
            $post    = get_post( $post_id );
            $keyword = $post ? wp_strip_all_tags( $post->post_content ) : '';
        }
        $keyword = mb_substr( $keyword, 0, 800 );
        $max_len = 0;
        switch ( $action ) {
            case 'title':
                $prompt  = 'Genera un Título SEO que tenga como máximo 60 caracteres y no corte ninguna palabra. Responde solo con el texto del título. Tema: ' . $keyword;
                $max_len = 60;
                break;
            case 'meta':
                $prompt  = 'Genera una Meta description que tenga como máximo 160 caracteres y no corte ninguna palabra. Responde solo con el texto de la descripción. Tema: ' . $keyword;
                $max_len = 160;
                break;
            case 'rewrite':
                $prompt = 'Reescribe el siguiente párrafo mejorando el SEO y usando mejores palabras clave:\n\n' . $paragraph;
                break;
            case 'alt':
                $prompt = 'Sugiere un texto alternativo descriptivo y optimizado para SEO para una imagen relacionada con: ' . $keyword;
                break;
            case 'post':
                $prompt = 'Redacta un artículo de aproximadamente 600 palabras optimizado para SEO sobre: ' . $keyword;
                break;
            default:
                wp_send_json_error( array( 'message' => 'Acción no válida' ) );
        }

        $result = $this->request_openai_content( $prompt, $max_len );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( $result );
    }

    public function ajax_generate_meta() {
        check_ajax_referer( 'b2sell_seo_meta' );
        $post_id = intval( $_POST['post_id'] ?? 0 );
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( array( 'message' => 'Permisos insuficientes' ) );
        }
        $content = sanitize_textarea_field( wp_unslash( $_POST['content'] ?? '' ) );
        if ( ! $content && $post_id ) {
            $post    = get_post( $post_id );
            $content = $post ? wp_strip_all_tags( $post->post_content ) : '';
        }
        $content = mb_substr( $content, 0, 1200 );
        $prompt = 'Basado en el siguiente contenido genera un título SEO (máximo 60 caracteres, sin cortar palabras) y una meta description (máximo 160 caracteres, sin cortar palabras). Devuelve un JSON con las claves "title" y "description":\n\n' . $content;

        $attempts     = 0;
        $max_attempts = 3;
        do {
            $response = $this->request_openai_content( $prompt );
            if ( is_wp_error( $response ) ) {
                wp_send_json_error( array( 'message' => $response->get_error_message() ) );
            }
            $raw  = trim( $response['content'] );
            $json = json_decode( $raw, true );
            if ( ! is_array( $json ) ) {
                $json = array();
                $lines = array_map( 'trim', explode( "\n", $raw ) );
                foreach ( $lines as $line ) {
                    if ( stripos( $line, 'title' ) === 0 || stripos( $line, 'título' ) === 0 ) {
                        $json['title'] = trim( substr( $line, strpos( $line, ':' ) + 1 ) );
                    }
                    if ( stripos( $line, 'description' ) === 0 || stripos( $line, 'descripción' ) === 0 ) {
                        $json['description'] = trim( substr( $line, strpos( $line, ':' ) + 1 ) );
                    }
                }
            }
            $title     = isset( $json['title'] ) ? trim( $json['title'] ) : '';
            $desc      = isset( $json['description'] ) ? trim( $json['description'] ) : '';
            $attempts++;
        } while ( ( mb_strlen( $title ) > 60 || mb_strlen( $desc ) > 160 ) && $attempts < $max_attempts );

        if ( '' === $title && '' === $desc ) {
            wp_send_json_error( array( 'message' => 'Respuesta inválida de OpenAI' ) );
        }

        $over_title = max( 0, mb_strlen( $title ) - 60 );
        $over_desc  = max( 0, mb_strlen( $desc ) - 160 );

        wp_send_json_success(
            array(
                'title'            => $title,
                'description'      => $desc,
                'over_title'       => $over_title,
                'over_description' => $over_desc,
            )
        );
    }

    public function ajax_generate_blog() {
        check_ajax_referer( 'b2sell_gpt_blog' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permisos insuficientes' ) );
        }

        $post_topic = sanitize_textarea_field( wp_unslash( $_POST['post_topic'] ?? '' ) );
        $keywords  = sanitize_text_field( wp_unslash( $_POST['keywords'] ?? '' ) );
        $word_count = intval( $_POST['word_count'] ?? 800 );
        $image_url = esc_url_raw( wp_unslash( $_POST['image_url'] ?? '' ) );
        $cta_text  = sanitize_text_field( wp_unslash( $_POST['cta_text'] ?? '' ) );
        $cta_page  = intval( $_POST['cta_page'] ?? 0 );

        $post_topic = mb_substr( $post_topic, 0, 500 );
        $keywords   = mb_substr( $keywords, 0, 500 );
        $word_count = max( 100, min( $word_count, 3000 ) );
        $cta_text   = mb_substr( $cta_text, 0, 200 );

        if ( empty( $post_topic ) || empty( $keywords ) || empty( $image_url ) || empty( $cta_text ) || ! $cta_page ) {
            wp_send_json_error( array( 'message' => 'Todos los campos son obligatorios.' ) );
        }

        $cta_link = get_permalink( $cta_page );
        if ( ! $cta_link ) {
            wp_send_json_error( array( 'message' => 'La página seleccionada no es válida.' ) );
        }

        $prompt  = 'Tema central: ' . $post_topic . "\n";
        $prompt .= 'Palabras clave: ' . $keywords . "\n";
        $prompt .= 'Cantidad de texto: ' . $word_count . " palabras\n\n";
        $prompt .= "Genera un post de blog optimizado para SEO con las siguientes condiciones:\n";
        $prompt .= "Estructura:\n\n";
        $prompt .= "<H1> con la keyword principal\n";
        $prompt .= "Opening persuasivo de 2–3 frases que incluya la keyword\n";
        $prompt .= 'Imagen de referencia ' . $image_url . "\n";
        $prompt .= "<H2> con una keyword secundaria\n";
        $prompt .= "Contenido en párrafo coherente\n";
        $prompt .= "<H2> con otra variación de keyword\n";
        $prompt .= "Contenido en párrafo con ejemplos o consejos\n";
        $prompt .= 'Call to action al final con el texto ' . $cta_text . ' que redirija a ' . $cta_link . "\n";
        $prompt .= 'El contenido debe ser natural, atractivo y optimizado para SEO sin cortar palabras ni repetir en exceso, y debe alinearse con el tema central indicado al inicio.';

        $result = $this->request_openai_content( $prompt );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        $content = wp_kses_post( $result['content'] );

        wp_send_json_success( array( 'content' => $content ) );
    }

    public function ajax_crear_blog_seo() {
        check_ajax_referer( 'b2sell_gpt_blog' );

        $requested_action = sanitize_key( $_POST['post_action'] ?? '' );
        if ( ! $requested_action ) {
            $requested_action = sanitize_key( $_POST['estado'] ?? '' );
        }
        if ( ! $requested_action ) {
            $action_param = sanitize_key( $_POST['action'] ?? '' );
            if ( in_array( $action_param, array( 'guardar', 'publicar' ), true ) ) {
                $requested_action = $action_param;
            }
        }

        $status = ( 'publicar' === $requested_action ) ? 'publish' : 'draft';

        if ( 'publish' === $status ) {
            if ( ! current_user_can( 'publish_posts' ) ) {
                wp_send_json_error( array( 'message' => 'Permisos insuficientes para publicar.' ) );
            }
        } elseif ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => 'Permisos insuficientes.' ) );
        }

        $raw_content = wp_unslash( $_POST['content'] ?? '' );
        if ( ! $raw_content ) {
            wp_send_json_error( array( 'message' => 'El contenido generado no es válido.' ) );
        }

        $title = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
        if ( '' === $title && preg_match( '/<h1[^>]*>(.*?)<\/h1>/is', $raw_content, $matches ) ) {
            $title = wp_strip_all_tags( $matches[1] );
        }
        if ( '' === $title ) {
            $title = mb_substr( wp_strip_all_tags( $raw_content ), 0, 80 );
        }
        if ( '' === $title ) {
            $title = 'Entrada generada con GPT';
        }

        $cta_page  = intval( $_POST['cta_page'] ?? 0 );
        $cta_text  = sanitize_text_field( wp_unslash( $_POST['cta_text'] ?? '' ) );
        $post_topic = sanitize_textarea_field( wp_unslash( $_POST['post_topic'] ?? '' ) );

        $post_topic = mb_substr( $post_topic, 0, 500 );

        if ( $cta_page ) {
            $cta_url = get_permalink( $cta_page );
            if ( $cta_url ) {
                $cta_label = $cta_text ? $cta_text : get_the_title( $cta_page );
                $cta_label = $cta_label ? $cta_label : __( 'Ver más', 'b2sell-seo-assistant' );
                $button     = sprintf(
                    '<p class="b2sell-blog-cta"><a class="button button-primary b2sell-blog-cta-button" href="%s">%s</a></p>',
                    esc_url( $cta_url ),
                    esc_html( $cta_label )
                );
                $raw_content .= $button;
            }
        }

        $content = wp_kses_post( $raw_content );

        $post_id = wp_insert_post(
            array(
                'post_type'    => 'post',
                'post_status'  => $status,
                'post_title'   => $title,
                'post_content' => $content,
            ),
            true
        );

        if ( is_wp_error( $post_id ) ) {
            wp_send_json_error( array( 'message' => $post_id->get_error_message() ) );
        }

        if ( $post_topic ) {
            update_post_meta( $post_id, '_seo_post_topic', $post_topic );
        }

        $image_url = esc_url_raw( wp_unslash( $_POST['image_url'] ?? '' ) );
        if ( $image_url ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';

            $attachment_id = media_sideload_image( $image_url, $post_id, $title, 'id' );
            if ( ! is_wp_error( $attachment_id ) ) {
                set_post_thumbnail( $post_id, $attachment_id );
            }
        }

        $message = ( 'publish' === $status ) ? 'Entrada publicada correctamente.' : 'Borrador creado correctamente.';

        $response = array(
            'message'   => $message,
            'post_id'   => $post_id,
            'edit_link' => get_edit_post_link( $post_id, 'raw' ),
            'view_link' => get_permalink( $post_id ),
        );

        wp_send_json_success( $response );
    }

    public function ajax_save_blog_post() {
        check_ajax_referer( 'b2sell_gpt_blog' );

        $status = sanitize_key( $_POST['status'] ?? 'draft' );
        $status = ( 'publish' === $status ) ? 'publish' : 'draft';

        if ( 'publish' === $status ) {
            if ( ! current_user_can( 'publish_posts' ) ) {
                wp_send_json_error( array( 'message' => 'Permisos insuficientes para publicar.' ) );
            }
        } elseif ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => 'Permisos insuficientes' ) );
        }

        $content = wp_kses_post( wp_unslash( $_POST['content'] ?? '' ) );
        if ( ! $content ) {
            wp_send_json_error( array( 'message' => 'El contenido generado no es válido.' ) );
        }

        $title = '';
        if ( preg_match( '/<h1[^>]*>(.*?)<\/h1>/is', $content, $matches ) ) {
            $title = wp_strip_all_tags( $matches[1] );
        }
        if ( '' === $title ) {
            $title = mb_substr( wp_strip_all_tags( $content ), 0, 80 );
        }
        if ( '' === $title ) {
            $title = 'Entrada generada con GPT';
        }

        $post_id = wp_insert_post(
            array(
                'post_type'    => 'post',
                'post_status'  => $status,
                'post_title'   => $title,
                'post_content' => $content,
            ),
            true
        );

        if ( is_wp_error( $post_id ) ) {
            wp_send_json_error( array( 'message' => $post_id->get_error_message() ) );
        }

        $message = ( 'publish' === $status ) ? 'Entrada publicada correctamente.' : 'Borrador creado correctamente.';
        $data    = array(
            'message' => $message,
            'post_id' => $post_id,
        );

        $edit_link = get_edit_post_link( $post_id, 'raw' );
        if ( $edit_link ) {
            $data['edit_link'] = $edit_link;
        }

        $view_link = get_permalink( $post_id );
        if ( $view_link ) {
            $data['view_link'] = $view_link;
        }

        wp_send_json_success( $data );
    }

    private function request_openai_content( $prompt, $max_len = 0 ) {
        $api_key = get_option( 'b2sell_openai_api_key', '' );
        if ( ! $api_key ) {
            return new WP_Error( 'b2sell_missing_api_key', 'API Key no configurada' );
        }

        $attempts     = 0;
        $max_attempts = 3;
        $content      = '';

        do {
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

                return new WP_Error( 'b2sell_openai_error', $msg );
            }

            $data = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( isset( $data['error']['message'] ) ) {
                return new WP_Error( 'b2sell_openai_error', $data['error']['message'] );
            }

            if ( ! isset( $data['choices'][0]['message']['content'] ) ) {
                return new WP_Error( 'b2sell_openai_error', 'Respuesta inválida de OpenAI' );
            }

            $content = trim( $data['choices'][0]['message']['content'] );
            $attempts++;
        } while ( $max_len && mb_strlen( $content ) > $max_len && $attempts < $max_attempts );

        $over = $max_len ? max( 0, mb_strlen( $content ) - $max_len ) : 0;

        return array(
            'content' => $content,
            'over'    => $over,
        );
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
            case 'alt':
                $post     = get_post( $post_id );
                $html     = $post->post_content;
                $dom      = new DOMDocument();
                libxml_use_internal_errors( true );
                $dom->loadHTML( '<meta http-equiv="content-type" content="text/html; charset=utf-8" />' . $html );
                libxml_clear_errors();
                $imgs = $dom->getElementsByTagName( 'img' );
                $target_src = sanitize_text_field( $_POST['image_src'] ?? '' );
                foreach ( $imgs as $img ) {
                    if ( $target_src ) {
                        if ( $img->getAttribute( 'src' ) === $target_src ) {
                            $img->setAttribute( 'alt', $content );
                            break;
                        }
                    } elseif ( '' === $img->getAttribute( 'alt' ) ) {
                        $img->setAttribute( 'alt', $content );
                        break;
                    }
                }
                $body       = $dom->getElementsByTagName( 'body' )->item( 0 );
                $new_html   = '';
                foreach ( $body->childNodes as $child ) {
                    $new_html .= $dom->saveHTML( $child );
                }
                wp_update_post( array( 'ID' => $post_id, 'post_content' => $new_html ) );
                break;
            default:
                wp_send_json_error( 'Acción no válida' );
        }
        wp_send_json_success();
    }
}
