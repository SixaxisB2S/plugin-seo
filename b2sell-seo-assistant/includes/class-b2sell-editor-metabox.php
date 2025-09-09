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
            <div class="b2sell-gpt-inner" style="background:#fff;padding:20px;max-width:600px;width:90%;max-height:80%;overflow:auto;">
                <div class="b2sell-gpt-brand-header" style="text-align:center;font-weight:bold;color:#5450FF;margin-bottom:10px;">B2SELL</div>
                <h2>Sugerencias de GPT</h2>
                <div id="b2sell-gpt-suggestions"><p>Generando...</p></div>
                <p><button class="button" id="b2sell-gpt-close">Cerrar</button></p>
                <div class="b2sell-gpt-brand-footer" style="text-align:center;font-size:12px;color:#5450FF;margin-top:10px;">B2SELL</div>
            </div>
        </div>
        <style>
        .b2sell-snippet-preview{margin-top:20px;font-family:Arial,sans-serif}
        .b2sell-snippet-tabs{margin-bottom:10px}
        .b2sell-snippet-tabs button{margin-right:5px}
        .b2sell-snippet-view{border:1px solid #ccc;padding:10px}
        .b2sell-snippet-desktop{max-width:600px}
        .b2sell-snippet-mobile{max-width:360px}
        .b2sell-snippet-title{color:#5450FF;font-size:18px;margin-bottom:2px;display:block}
        .b2sell-snippet-url{color:green;font-size:14px;margin-bottom:2px;display:block}
        .b2sell-snippet-desc{color:#5f6368;font-size:13px;line-height:1.4}
        .b2sell-snippet-desktop .b2sell-snippet-desc{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
        .b2sell-snippet-tab.active{font-weight:bold}
        </style>
        <script>
        jQuery(function($){
            const b2sellSnippetUrl='<?php echo esc_js( home_url() ); ?>';
            function updateSnippet(){
                const t=$('#b2sell-suggest-title').val()||'';
                const m=$('#b2sell-suggest-meta').val()||'';
                $('.b2sell-snippet-view .b2sell-snippet-title').text(t);
                $('.b2sell-snippet-view .b2sell-snippet-desc').text(m);
                $('.b2sell-snippet-view .b2sell-snippet-url').text(b2sellSnippetUrl);
            }
            $('#b2sell-gpt-improve').on('click',function(){
                $('#b2sell-gpt-modal').css('display','flex');
                $('#b2sell-gpt-suggestions').html('<p>Generando...</p>');
                const postID = <?php echo intval( $post->ID ); ?>;
                const getTitle=function(){
                    if(typeof wp!=='undefined'&&wp.data){
                        return wp.data.select('core/editor').getEditedPostAttribute('title');
                    }
                    return $('#title').val()||$('input[name="post_title"]').val();
                };
                const getContent=function(){
                    if(typeof wp!=='undefined'&&wp.data){
                        return wp.data.select('core/editor').getEditedPostContent();
                    }
                    if(typeof tinymce!=='undefined'&&tinymce.activeEditor){
                        return tinymce.activeEditor.getContent({format:'text'});
                    }
                    return $('#content').val();
                };
                const title=getTitle();
                const firstParagraph=getContent().split('\n')[0];
                $.when(
                    $.post(ajaxurl,{action:'b2sell_gpt_generate',gpt_action:'title',keyword:title,post_id:postID,_wpnonce:'<?php echo esc_js( $nonce ); ?>'}),
                    $.post(ajaxurl,{action:'b2sell_gpt_generate',gpt_action:'meta',keyword:title,post_id:postID,_wpnonce:'<?php echo esc_js( $nonce ); ?>'}),
                    $.post(ajaxurl,{action:'b2sell_gpt_generate',gpt_action:'rewrite',paragraph:firstParagraph,post_id:postID,_wpnonce:'<?php echo esc_js( $nonce ); ?>'})
                ).done(function(titleRes,metaRes,rewriteRes){
                    let html='';
                    if(titleRes[0].success){
                        html+='<h3>Título optimizado</h3><textarea id="b2sell-suggest-title" class="b2sell-suggest-text" style="width:100%;">'+titleRes[0].data.content+'</textarea><button class="button b2sell-copy" data-target="#b2sell-suggest-title">Copiar</button> <button class="button b2sell-insert" data-action="title" data-target="#b2sell-suggest-title">Insertar</button>';
                    }
                    if(metaRes[0].success){
                        html+='<h3>Meta description</h3><textarea id="b2sell-suggest-meta" class="b2sell-suggest-text" style="width:100%;">'+metaRes[0].data.content+'</textarea><button class="button b2sell-copy" data-target="#b2sell-suggest-meta">Copiar</button> <button class="button b2sell-insert" data-action="meta" data-target="#b2sell-suggest-meta">Insertar</button>';
                    }
                    if(rewriteRes[0].success){
                        html+='<h3>Párrafo reescrito</h3><pre>'+rewriteRes[0].data.content+'</pre><button class="button b2sell-copy" data-text="'+rewriteRes[0].data.content.replace(/"/g,'&quot;')+'">Copiar</button> <button class="button b2sell-insert" data-action="rewrite" data-content="'+rewriteRes[0].data.content.replace(/"/g,'&quot;')+'">Insertar</button>';
                    }
                    html+='<div class="b2sell-snippet-preview"><div class="b2sell-snippet-tabs"><button type="button" class="b2sell-snippet-tab active" data-view="desktop">Vista Desktop</button><button type="button" class="b2sell-snippet-tab" data-view="mobile">Vista Móvil</button></div><div class="b2sell-snippet-desktop b2sell-snippet-view"><span class="b2sell-snippet-title"></span><span class="b2sell-snippet-url"></span><span class="b2sell-snippet-desc"></span></div><div class="b2sell-snippet-mobile b2sell-snippet-view" style="display:none;"><span class="b2sell-snippet-url"></span><span class="b2sell-snippet-title"></span><span class="b2sell-snippet-desc"></span></div></div>';
                    $('#b2sell-gpt-suggestions').html(html);
                    updateSnippet();
                }).fail(function(){
                    $('#b2sell-gpt-suggestions').html('<p>Error al obtener sugerencias.</p>');
                });
            });
            $('#b2sell-gpt-close').on('click',function(){
                $('#b2sell-gpt-modal').hide();
            });
            $(document).on('input','#b2sell-suggest-title,#b2sell-suggest-meta',updateSnippet);
            $(document).on('click','.b2sell-copy',function(){
                const target=$(this).data('target');
                if(target){
                    navigator.clipboard.writeText($(target).val());
                }else{
                    navigator.clipboard.writeText($(this).data('text'));
                }
            });
            $(document).on('click','.b2sell-insert',function(){
                const action=$(this).data('action');
                const target=$(this).data('target');
                const content=target?$(target).val():$(this).data('content');
                const postID=<?php echo intval( $post->ID ); ?>;
                $.post(ajaxurl,{action:'b2sell_gpt_insert',gpt_action:action,post_id:postID,content:content,_wpnonce:'<?php echo esc_js( $nonce ); ?>'},function(res){
                    alert(res.success?'Contenido insertado':res.data);
                    updateSnippet();
                });
            });
            $(document).on('click','.b2sell-snippet-tab',function(){
                const view=$(this).data('view');
                $('.b2sell-snippet-tab').removeClass('active');
                $(this).addClass('active');
                $('.b2sell-snippet-view').hide();
                if(view==='desktop'){
                    $('.b2sell-snippet-desktop').show();
                }else{
                    $('.b2sell-snippet-mobile').show();
                }
            });
        });
        </script>
        <?php
    }
}

new B2Sell_SEO_Editor_Metabox();
