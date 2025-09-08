<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class B2Sell_SEO_Editor_Metabox {
    public function __construct() {
        add_action( 'add_meta_boxes', array( $this, 'register' ) );
    }

    public function register() {
        foreach ( array( 'post', 'page' ) as $type ) {
            add_meta_box(
                'b2sell-seo-metabox',
                'B2SELL SEO',
                array( $this, 'render' ),
                $type,
                'side',
                'high'
            );
        }
    }

    public function render( $post ) {
        $history = get_post_meta( $post->ID, '_b2sell_seo_history', true );
        $latest  = is_array( $history ) ? end( $history ) : false;
        $score   = $latest ? intval( $latest['score'] ) : 'N/A';
        $recs    = $latest && ! empty( $latest['recommendations'] ) ? $latest['recommendations'] : array();
        $nonce   = wp_create_nonce( 'b2sell_gpt_nonce' );
        ?>
        <div class="b2sell-seo-box">
            <p><strong>Puntaje SEO:</strong> <?php echo esc_html( $score ); ?>/100</p>
            <?php if ( $recs ) : ?>
                <ol>
                    <?php foreach ( array_slice( $recs, 0, 3 ) as $r ) : ?>
                        <li><?php echo esc_html( $r ); ?></li>
                    <?php endforeach; ?>
                </ol>
            <?php else : ?>
                <p>No hay recomendaciones disponibles.</p>
            <?php endif; ?>
            <p><button type="button" class="button button-primary" id="b2sell-gpt-improve">Mejorar con GPT</button></p>
        </div>
        <div id="b2sell-gpt-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);align-items:center;justify-content:center;z-index:100000;">
            <div style="background:#fff;padding:20px;max-width:600px;width:90%;max-height:80%;overflow:auto;">
                <h2>Sugerencias de GPT</h2>
                <div id="b2sell-gpt-suggestions"><p>Generando...</p></div>
                <p><button class="button" id="b2sell-gpt-close">Cerrar</button></p>
            </div>
        </div>
        <script>
        jQuery(function($){
            $('#b2sell-gpt-improve').on('click',function(){
                $('#b2sell-gpt-modal').css('display','flex');
                $('#b2sell-gpt-suggestions').html('<p>Generando...</p>');
                const postID = <?php echo intval( $post->ID ); ?>;
                const getTitle = function(){
                    if ( typeof wp !== 'undefined' && wp.data ) {
                        return wp.data.select('core/editor').getEditedPostAttribute('title');
                    }
                    return $('#title').val() || $('input[name="post_title"]').val();
                };
                const getContent = function(){
                    if ( typeof wp !== 'undefined' && wp.data ) {
                        return wp.data.select('core/editor').getEditedPostContent();
                    }
                    if ( typeof tinymce !== 'undefined' && tinymce.activeEditor ) {
                        return tinymce.activeEditor.getContent({format:'text'});
                    }
                    return $('#content').val();
                };
                const title = getTitle();
                const firstParagraph = getContent().split('\n')[0];
                $.when(
                    $.post(ajaxurl,{action:'b2sell_gpt_generate',gpt_action:'title',keyword:title,post_id:postID,_wpnonce:'<?php echo esc_js( $nonce ); ?>'}),
                    $.post(ajaxurl,{action:'b2sell_gpt_generate',gpt_action:'meta',keyword:title,post_id:postID,_wpnonce:'<?php echo esc_js( $nonce ); ?>'}),
                    $.post(ajaxurl,{action:'b2sell_gpt_generate',gpt_action:'rewrite',paragraph:firstParagraph,post_id:postID,_wpnonce:'<?php echo esc_js( $nonce ); ?>'})
                ).done(function(titleRes,metaRes,rewriteRes){
                    let html='';
                    if(titleRes[0].success){
                        html+='<h3>Título optimizado</h3><pre>'+titleRes[0].data.content+'</pre><button class="button b2sell-copy" data-text="'+titleRes[0].data.content.replace(/"/g,'&quot;')+'">Copiar</button> <button class="button b2sell-insert" data-action="title" data-content="'+titleRes[0].data.content.replace(/"/g,'&quot;')+'">Insertar</button>';
                    }
                    if(metaRes[0].success){
                        html+='<h3>Meta description</h3><pre>'+metaRes[0].data.content+'</pre><button class="button b2sell-copy" data-text="'+metaRes[0].data.content.replace(/"/g,'&quot;')+'">Copiar</button> <button class="button b2sell-insert" data-action="meta" data-content="'+metaRes[0].data.content.replace(/"/g,'&quot;')+'">Insertar</button>';
                    }
                    if(rewriteRes[0].success){
                        html+='<h3>Párrafo reescrito</h3><pre>'+rewriteRes[0].data.content+'</pre><button class="button b2sell-copy" data-text="'+rewriteRes[0].data.content.replace(/"/g,'&quot;')+'">Copiar</button> <button class="button b2sell-insert" data-action="rewrite" data-content="'+rewriteRes[0].data.content.replace(/"/g,'&quot;')+'">Insertar</button>';
                    }
                    $('#b2sell-gpt-suggestions').html(html);
                }).fail(function(){
                    $('#b2sell-gpt-suggestions').html('<p>Error al obtener sugerencias.</p>');
                });
            });
            $('#b2sell-gpt-close').on('click',function(){
                $('#b2sell-gpt-modal').hide();
            });
            $(document).on('click','.b2sell-copy',function(){
                navigator.clipboard.writeText($(this).data('text'));
            });
            $(document).on('click','.b2sell-insert',function(){
                const action=$(this).data('action');
                const content=$(this).data('content');
                const postID=<?php echo intval( $post->ID ); ?>;
                $.post(ajaxurl,{action:'b2sell_gpt_insert',gpt_action:action,post_id:postID,content:content,_wpnonce:'<?php echo esc_js( $nonce ); ?>'},function(res){
                    alert(res.success?'Contenido insertado':res.data);
                });
            });
        });
        </script>
        <?php
    }
}

new B2Sell_SEO_Editor_Metabox();
