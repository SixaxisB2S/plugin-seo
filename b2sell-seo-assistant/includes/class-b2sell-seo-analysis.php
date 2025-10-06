<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class B2Sell_SEO_Analysis {

    public function __construct() {
        add_action( 'wp_ajax_b2sell_generate_product_alt', array( $this, 'ajax_generate_product_alt' ) );
        add_action( 'wp_ajax_b2sell_generate_product_alt_batch', array( $this, 'ajax_generate_product_alt_batch' ) );
    }

    public function render_admin_page() {
        $section = isset( $_GET['section'] ) ? sanitize_key( $_GET['section'] ) : 'content';
        if ( ! in_array( $section, array( 'products', 'content' ), true ) ) {
            $section = 'content';
        }

        if ( isset( $_GET['post_id'] ) ) {
            $this->render_analysis( intval( $_GET['post_id'] ), $section );
        } elseif ( isset( $_GET['technical'] ) ) {
            $this->render_technical();
        } else {
            $this->render_list();
        }
    }

    private function render_list() {
        $section = isset( $_GET['section'] ) ? sanitize_key( $_GET['section'] ) : 'content';
        if ( ! in_array( $section, array( 'products', 'content' ), true ) ) {
            $section = 'content';
        }

        echo '<div class="wrap">';
        echo '<h1>Análisis SEO</h1>';

        $base_url     = admin_url( 'admin.php?page=b2sell-seo-analisis' );
        $content_url  = add_query_arg( 'section', 'content', $base_url );
        $products_url = add_query_arg( 'section', 'products', $base_url );

        echo '<h2 class="nav-tab-wrapper">';
        echo '<a href="' . esc_url( $content_url ) . '" class="nav-tab' . ( 'products' !== $section ? ' nav-tab-active' : '' ) . '">Contenido</a>';
        if ( post_type_exists( 'product' ) ) {
            echo '<a href="' . esc_url( $products_url ) . '" class="nav-tab' . ( 'products' === $section ? ' nav-tab-active' : '' ) . '">Productos</a>';
        }
        echo '</h2>';

        if ( 'products' === $section ) {
            $this->render_products_tab();
        } else {
            $low_score = isset( $_GET['low_score'] );
            $order     = isset( $_GET['order'] ) ? sanitize_text_field( $_GET['order'] ) : '';
            $this->render_content_tab( $low_score, $order );
        }

        echo '</div>';
    }

    private function render_content_tab( $low_score, $order ) {
        $posts      = get_posts(
            array(
                'post_type'   => array( 'post', 'page' ),
                'post_status' => 'publish',
                'numberposts' => -1,
            )
        );

        $rows = array();
        foreach ( $posts as $p ) {
            $history = get_post_meta( $p->ID, '_b2sell_seo_history', true );
            $last    = is_array( $history ) ? end( $history ) : false;
            $date    = $last ? $last['date'] : '-';
            $score   = $last ? intval( $last['score'] ) : '-';

            $rows[] = array(
                'ID'      => $p->ID,
                'title'   => $p->post_title,
                'type'    => $p->post_type,
                'date'    => $date,
                'score'   => $score,
                'history' => is_array( $history ) ? array_slice( $history, -5 ) : array(),
            );
        }

        if ( $low_score ) {
            $rows = array_filter(
                $rows,
                function( $r ) {
                    return '-' !== $r['score'] && $r['score'] < 60;
                }
            );
        }

        if ( 'date' === $order ) {
            usort(
                $rows,
                function( $a, $b ) {
                    return strcmp( $b['date'], $a['date'] );
                }
            );
        } elseif ( 'score' === $order ) {
            usort(
                $rows,
                function( $a, $b ) {
                    return intval( $b['score'] ) - intval( $a['score'] );
                }
            );
        }

        echo '<form method="get" style="margin-bottom:15px;">';
        echo '<input type="hidden" name="page" value="b2sell-seo-analisis" />';
        echo '<input type="hidden" name="section" value="content" />';
        echo '<label><input type="checkbox" name="low_score" value="1" ' . checked( $low_score, true, false ) . ' /> Mostrar solo puntaje bajo (&lt;60)</label> ';
        echo '<select name="order">';
        echo '<option value="">Ordenar...</option>';
        echo '<option value="date" ' . selected( $order, 'date', false ) . '>Fecha de análisis</option>';
        echo '<option value="score" ' . selected( $order, 'score', false ) . '>Puntaje de mayor a menor</option>';
        echo '</select> ';
        submit_button( 'Filtrar', '', '', false );
        echo '</form>';

        $technical_url = add_query_arg(
            array(
                'page'      => 'b2sell-seo-analisis',
                'technical' => 1,
                'section'   => 'content',
            ),
            admin_url( 'admin.php' )
        );

        echo '<p><a class="button" href="' . esc_url( $technical_url ) . '">SEO Técnico</a></p>';

        echo '<p><button id="b2sell-export-csv" class="button">Exportar CSV</button> ';
        echo '<button id="b2sell-export-pdf" class="button">Exportar PDF</button></p>';

        echo '<table id="b2sell-seo-table" class="widefat fixed">';
        echo '<thead><tr><th>Título</th><th>Tipo</th><th>Último análisis</th><th>Puntaje</th><th></th></tr></thead><tbody>';
        foreach ( $rows as $row ) {
            $link    = add_query_arg(
                array(
                    'page'    => 'b2sell-seo-analisis',
                    'post_id' => $row['ID'],
                    'section' => 'content',
                ),
                admin_url( 'admin.php' )
            );
            $history = esc_attr( wp_json_encode( $row['history'] ) );
            echo '<tr class="b2sell-history-row" data-history="' . $history . '">';
            echo '<td>' . esc_html( $row['title'] ) . '</td>';
            echo '<td>' . esc_html( $row['type'] ) . '</td>';
            echo '<td>' . esc_html( $row['date'] ) . '</td>';
            echo '<td>' . esc_html( $row['score'] ) . '</td>';
            echo '<td><a class="button" href="' . esc_url( $link ) . '">Analizar</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<div id="b2sell-history" style="display:none;margin-top:20px;"><canvas id="b2sell-history-chart" height="100"></canvas></div>';

        $this->render_meta_table( $posts );

        $logo = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAMgAAAA8CAIAAACsOWLGAAAACXBIWXMAAA7EAAAOxAGVKw4bAAABSElEQVR4nO3b0WqDMBiG4Wbs/m/ZHQghSzTWdh8r+jxnbRQKfdG/KZZlWR7w177++wNwTcIiQlhECIsIYREhLCKERYSwiBAWEcIiQlhECIsIYREhLCKERYSwiBAWEcIiQlhECIsIYREhLCK+58ullO6d7nGx9oB2ae/E8f1uafNxtMkSn+kgrFX9RksppZT25aPJoi6NHXQnSuTyzt0K2266eiYXpHVVTLfy1BWramMSChNPhbU3SI3H1ObWe197wN4EJtBLemvGqsahapzxzVi38vqMVfnJxujdfSxVselcWF1Ge1WNAxZ3szEw/Vre3yCd7CxMTjzcID27xGc6CAte479CIoRFhLCIEBYRwiJCWEQIiwhhESEsIoRFhLCIEBYRwiJCWEQIiwhhESEsIoRFhLCIEBYRwiJCWEQIiwhhESEsIoRFxA9KdYd0WrGpfwAAAABJRU5ErkJggg==';
        echo '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';
        echo '<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>';
        echo '<script>';
        echo 'const b2sellLogo = "' . esc_js( $logo ) . '";';
        echo 'document.getElementById("b2sell-export-csv").addEventListener("click",function(){var rows=document.querySelectorAll("#b2sell-seo-table tbody tr");var csv="Logo,"+b2sellLogo+"\nTítulo,Tipo,Último análisis,Puntaje\n";rows.forEach(function(r){var c=r.querySelectorAll("td");csv+=c[0].innerText+","+c[1].innerText+","+c[2].innerText+","+c[3].innerText+"\n";});csv+="\nDesarrollado por B2Sell SPA";var blob=new Blob([csv],{type:"text/csv;charset=utf-8;"});var link=document.createElement("a");link.href=URL.createObjectURL(blob);link.download="b2sell-seo-report.csv";link.click();});';
        echo 'document.getElementById("b2sell-export-pdf").addEventListener("click",function(){const {jsPDF}=window.jspdf;var doc=new jsPDF();var img=new Image();img.src=b2sellLogo;img.onload=function(){doc.addImage(img,"PNG",10,10,40,15);doc.setFontSize(16);doc.text("Reporte de Análisis SEO",10,30);var y=40;doc.setFontSize(10);document.querySelectorAll("#b2sell-seo-table tbody tr").forEach(function(r){var c=r.querySelectorAll("td");doc.text(c[0].innerText+" | "+c[1].innerText+" | "+c[2].innerText+" | "+c[3].innerText,10,y);y+=10;});doc.text("Desarrollado por B2Sell SPA",10,280);doc.save("b2sell-seo-report.pdf");};});';
        echo 'var ctx=document.getElementById("b2sell-history-chart").getContext("2d");var chart=new Chart(ctx,{type:"line",data:{labels:[],datasets:[{label:"Puntaje",data:[],borderColor:"#0073aa",fill:false}]},options:{scales:{y:{beginAtZero:true,max:100}}}});';
        echo 'document.querySelectorAll(".b2sell-history-row").forEach(function(row){row.addEventListener("click",function(e){if(e.target.tagName.toLowerCase()==="a"){return;}var data=JSON.parse(this.dataset.history||"[]");if(!data.length){return;}var labels=data.map(function(i){return i.date;});var scores=data.map(function(i){return parseInt(i.score);});chart.data.labels=labels;chart.data.datasets[0].data=scores;chart.update();document.getElementById("b2sell-history").style.display="block";});});';
        echo '</script>';
    }

    private function render_products_tab() {
        if ( ! post_type_exists( 'product' ) ) {
            echo '<p>No se detecta el tipo de contenido de productos. Verifica que WooCommerce esté activo.</p>';
            return;
        }

        $products = get_posts(
            array(
                'post_type'   => 'product',
                'post_status' => 'publish',
                'numberposts' => -1,
            )
        );

        if ( empty( $products ) ) {
            echo '<p>No hay productos publicados actualmente.</p>';
            return;
        }

        echo '<p><button id="b2sell-product-alt-batch" class="button button-primary">Generar ALT masivo</button></p>';

        echo '<table id="b2sell-products-table" class="widefat fixed">';
        echo '<thead><tr><th>Producto</th><th>SKU</th><th>Último análisis</th><th>Puntaje</th><th>Imágenes sin ALT</th><th></th></tr></thead><tbody>';

        foreach ( $products as $product ) {
            $history = get_post_meta( $product->ID, '_b2sell_seo_history', true );
            $last    = is_array( $history ) ? end( $history ) : false;
            $date    = $last ? $last['date'] : '-';
            $score   = $last ? intval( $last['score'] ) : '-';
            $sku     = get_post_meta( $product->ID, '_sku', true );
            $stats   = $this->get_product_image_alt_stats( $product->ID );
            $missing = ( 0 === $stats['total'] ) ? '-' : $stats['missing'] . ' / ' . $stats['total'];
            $analyze = add_query_arg(
                array(
                    'page'    => 'b2sell-seo-analisis',
                    'post_id' => $product->ID,
                    'section' => 'products',
                ),
                admin_url( 'admin.php' )
            );
            echo '<tr data-id="' . esc_attr( $product->ID ) . '">';
            echo '<td>' . esc_html( $product->post_title ) . '</td>';
            echo '<td>' . esc_html( $sku ) . '</td>';
            echo '<td>' . esc_html( $date ) . '</td>';
            echo '<td>' . esc_html( $score ) . '</td>';
            echo '<td class="b2sell-product-missing">' . esc_html( $missing ) . '</td>';
            echo '<td><a class="button" href="' . esc_url( $analyze ) . '">Analizar</a> <button class="button b2sell-product-alt" data-id="' . esc_attr( $product->ID ) . '">Generar ALT</button></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        echo '<p>La descripción ALT se genera automáticamente usando el nombre del producto.</p>';

        $this->render_meta_table( $products, 'Metadatos SEO de productos' );

        $nonce = wp_create_nonce( 'b2sell_product_alt' );
        echo '<script>var b2sellProductAltNonce="' . esc_js( $nonce ) . '";</script>';
        ?>
        <script>
        jQuery(function($){
            $('#b2sell-products-table').on('click','.b2sell-product-alt',function(e){
                e.preventDefault();
                var button=$(this);
                if(button.prop('disabled')){
                    return;
                }
                var original=button.text();
                button.prop('disabled',true).text('Generando...');
                $.post(ajaxurl,{action:'b2sell_generate_product_alt',product_id:button.data('id'),nonce:b2sellProductAltNonce},function(res){
                    button.prop('disabled',false).text(original);
                    if(res.success){
                        var data=res.data||{};
                        alert(data.message||'ALT generados correctamente.');
                        var row=$('#b2sell-products-table tr[data-id="'+data.product_id+'"]');
                        if(row.length){
                            var display=data.total?data.missing+' / '+data.total:'-';
                            row.find('.b2sell-product-missing').text(display);
                        }
                    }else{
                        alert(res.data&&res.data.message?res.data.message:res.data);
                    }
                }).fail(function(){
                    button.prop('disabled',false).text(original);
                    alert('Error al conectar con el servidor.');
                });
            });
            $('#b2sell-product-alt-batch').on('click',function(e){
                e.preventDefault();
                var button=$(this);
                if(button.prop('disabled')){
                    return;
                }
                if(!confirm('¿Generar ALT para todos los productos?')){
                    return;
                }
                var original=button.text();
                button.prop('disabled',true).text('Procesando...');
                $.post(ajaxurl,{action:'b2sell_generate_product_alt_batch',nonce:b2sellProductAltNonce},function(res){
                    button.prop('disabled',false).text(original);
                    if(res.success){
                        var data=res.data||{};
                        alert(data.message||'ALT generados correctamente.');
                        if(Array.isArray(data.products)){
                            data.products.forEach(function(item){
                                var row=$('#b2sell-products-table tr[data-id="'+item.product_id+'"]');
                                if(row.length){
                                    var display=item.total?item.missing+' / '+item.total:'-';
                                    row.find('.b2sell-product-missing').text(display);
                                }
                            });
                        }
                    }else{
                        alert(res.data&&res.data.message?res.data.message:res.data);
                    }
                }).fail(function(){
                    button.prop('disabled',false).text(original);
                    alert('Error al conectar con el servidor.');
                });
            });
        });
        </script>
        <?php
    }

    private function render_meta_table( $items, $heading = 'Metadatos SEO' ) {
        echo '<h2 style="margin-top:40px;">' . esc_html( $heading ) . '</h2>';
        echo '<table class="widefat" id="b2sell-meta-table"><thead><tr><th>Título</th><th>Título SEO</th><th>Meta description</th><th>Acciones</th></tr></thead><tbody>';
        if ( empty( $items ) ) {
            echo '<tr><td colspan="4">No hay elementos disponibles.</td></tr>';
        } else {
            foreach ( $items as $p ) {
                $t       = get_post_meta( $p->ID, '_b2sell_seo_title', true );
                $d       = get_post_meta( $p->ID, '_b2sell_seo_description', true );
                $content = esc_attr( mb_substr( wp_strip_all_tags( $p->post_content ), 0, 1200 ) );
                echo '<tr data-id="' . esc_attr( $p->ID ) . '" data-content="' . $content . '"><td>' . esc_html( $p->post_title ) . '</td><td class="b2sell-meta-title">' . esc_html( $t ) . '</td><td class="b2sell-meta-desc">' . esc_html( $d ) . '</td><td><button class="button b2sell-meta-edit">Editar</button> <button class="button b2sell-meta-gpt">Generar con GPT</button></td></tr>';
            }
        }
        echo '</tbody></table>';

        echo '<div id="b2sell-meta-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);align-items:center;justify-content:center;z-index:100000;">';
        echo '<div style="background:#fff;padding:20px;max-width:600px;width:90%;max-height:90%;overflow:auto;">';
        echo '<h2>Editar metadatos</h2>';
        echo '<p><label>Título SEO<br /><input type="text" id="b2sell-meta-modal-title" style="width:100%;" /></label><br /><small><span id="b2sell-modal-title-count">0</span> caracteres</small><div id="b2sell-modal-title-warning" style="color:#c00;display:none;"></div></p>';
        echo '<p><label>Meta description<br /><textarea id="b2sell-meta-modal-desc" style="width:100%;" rows="3"></textarea></label><br /><small><span id="b2sell-modal-desc-count">0</span> caracteres</small><div id="b2sell-modal-desc-warning" style="color:#c00;display:none;"></div></p>';
        echo '<div class="b2sell-snippet-preview"><div class="b2sell-snippet-tabs"><button type="button" class="b2sell-snippet-tab active" data-view="desktop">Vista Desktop</button><button type="button" class="b2sell-snippet-tab" data-view="mobile">Vista Móvil</button></div><div class="b2sell-snippet-desktop b2sell-snippet-view"><span class="b2sell-snippet-title"></span><span class="b2sell-snippet-url">' . esc_html( home_url() ) . '</span><span class="b2sell-snippet-desc"></span></div><div class="b2sell-snippet-mobile b2sell-snippet-view" style="display:none;"><span class="b2sell-snippet-url">' . esc_html( home_url() ) . '</span><span class="b2sell-snippet-title"></span><span class="b2sell-snippet-desc"></span></div></div>';
        echo '<p><button class="button button-primary" id="b2sell-meta-save">Guardar</button> <button class="button" id="b2sell-meta-cancel">Cancelar</button></p>';
        echo '</div></div>';
        echo '<div id="b2sell-meta-gpt" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);align-items:center;justify-content:center;z-index:100000;">';
        echo '<div style="background:#fff;padding:20px;max-width:600px;width:90%;max-height:90%;overflow:auto;">';
        echo '<h2>Sugerencias GPT</h2>';
        echo '<p><label>Título SEO<br/><input type="text" id="b2sell-gpt-modal-title" style="width:100%;" /></label> <small><span id="b2sell-gpt-title-count">0</span> caracteres</small><div id="b2sell-gpt-title-warning" style="color:#c00;display:none;"></div></p>';
        echo '<p><label>Meta description<br/><textarea id="b2sell-gpt-modal-desc" rows="3" style="width:100%;"></textarea></label> <small><span id="b2sell-gpt-desc-count">0</span> caracteres</small><div id="b2sell-gpt-desc-warning" style="color:#c00;display:none;"></div></p>';
        echo '<div id="b2sell-gpt-error" style="color:#c00;display:none;"></div>';
        echo '<div class="b2sell-snippet-preview"><div class="b2sell-snippet-tabs"><button type="button" class="b2sell-snippet-tab active" data-view="desktop">Vista Desktop</button><button type="button" class="b2sell-snippet-tab" data-view="mobile">Vista Móvil</button></div><div class="b2sell-snippet-desktop b2sell-snippet-view"><span class="b2sell-snippet-title"></span><span class="b2sell-snippet-url">' . esc_html( home_url() ) . '</span><span class="b2sell-snippet-desc"></span></div><div class="b2sell-snippet-mobile b2sell-snippet-view" style="display:none;"><span class="b2sell-snippet-url">' . esc_html( home_url() ) . '</span><span class="b2sell-snippet-title"></span><span class="b2sell-snippet-desc"></span></div></div>';
        echo '<p><button class="button button-primary" id="b2sell-gpt-apply">Aplicar</button> <button class="button" id="b2sell-gpt-cancel">Cerrar</button></p>';
        echo '</div></div>';

        $nonce = wp_create_nonce( 'b2sell_seo_meta' );
        echo '<script>var b2sellSeoNonce="' . esc_js( $nonce ) . '";</script>';
        echo '<style>.b2sell-snippet-preview{margin-top:20px;font-family:Arial,sans-serif}.b2sell-snippet-tabs{margin-bottom:10px}.b2sell-snippet-tabs button{margin-right:5px}.b2sell-snippet-view{border:1px solid #ccc;padding:10px}.b2sell-snippet-desktop{max-width:600px}.b2sell-snippet-mobile{max-width:360px}.b2sell-snippet-title{color:#5450FF;font-size:18px;margin-bottom:2px;display:block}.b2sell-snippet-url{color:green;font-size:14px;margin-bottom:2px;display:block}.b2sell-snippet-desc{color:#5f6368;font-size:13px;line-height:1.4}.b2sell-snippet-desktop .b2sell-snippet-desc{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}.b2sell-snippet-tab.active{font-weight:bold}</style>';
        ?>
        <script>
        jQuery(function($){
            var currentId=0;
            function truncate(str,max){return str.length>max?str.substring(0,max).trim()+"...":str;}
            function updateModal(){
                var t=$('#b2sell-meta-modal-title').val();
                var d=$('#b2sell-meta-modal-desc').val();
                $('#b2sell-modal-title-count').text(t.length).css('color',t.length>60?'red':'');
                $('#b2sell-modal-title-warning').text('Excede por '+(t.length-60)+' caracteres').toggle(t.length>60);
                $('#b2sell-modal-desc-count').text(d.length).css('color',d.length>160?'red':'');
                $('#b2sell-modal-desc-warning').text('Excede por '+(d.length-160)+' caracteres').toggle(d.length>160);
                $('#b2sell-meta-modal .b2sell-snippet-title').text(truncate(t,60));
                $('#b2sell-meta-modal .b2sell-snippet-desc').text(truncate(d,160));
            }
            function updateGpt(){
                var t=$('#b2sell-gpt-modal-title').val();
                var d=$('#b2sell-gpt-modal-desc').val();
                $('#b2sell-gpt-title-count').text(t.length).css('color',t.length>60?'red':'');
                $('#b2sell-gpt-title-warning').text('Excede por '+(t.length-60)+' caracteres').toggle(t.length>60);
                $('#b2sell-gpt-desc-count').text(d.length).css('color',d.length>160?'red':'');
                $('#b2sell-gpt-desc-warning').text('Excede por '+(d.length-160)+' caracteres').toggle(d.length>160);
                $('#b2sell-meta-gpt .b2sell-snippet-title').text(truncate(t,60));
                $('#b2sell-meta-gpt .b2sell-snippet-desc').text(truncate(d,160));
            }
            $('#b2sell-meta-table').on('click','.b2sell-meta-edit',function(){
                var row=$(this).closest('tr');
                currentId=row.data('id');
                $('#b2sell-meta-modal-title').val(row.find('.b2sell-meta-title').text());
                $('#b2sell-meta-modal-desc').val(row.find('.b2sell-meta-desc').text());
                $('#b2sell-meta-modal').css('display','flex');
                updateModal();
            });
            $('#b2sell-meta-table').on('click','.b2sell-meta-gpt',function(){
                var row=$(this).closest('tr');
                currentId=row.data('id');
                var content=row.data('content');
                $('#b2sell-gpt-modal-title').val('');
                $('#b2sell-gpt-modal-desc').val('');
                $('#b2sell-gpt-error').hide().text('');
                $('#b2sell-meta-gpt').css('display','flex');
                updateGpt();
                $.post(ajaxurl,{action:'b2sell_generate_meta',post_id:currentId,content:content,_wpnonce:b2sellSeoNonce},function(res){
                    if(res.success){
                        $('#b2sell-gpt-modal-title').val(res.data.title);
                        $('#b2sell-gpt-modal-desc').val(res.data.description);
                        updateGpt();
                    }else{
                        $('#b2sell-gpt-error').text(res.data.message||res.data).show();
                    }
                }).fail(function(){
                    $('#b2sell-gpt-error').text('Error al conectar con el servidor').show();
                });
            });
            $('#b2sell-meta-cancel').on('click',function(){ $('#b2sell-meta-modal').hide(); });
            $('#b2sell-gpt-cancel').on('click',function(){ $('#b2sell-meta-gpt').hide(); });
            $('#b2sell-meta-modal-title,#b2sell-meta-modal-desc').on('input',updateModal);
            $('#b2sell-gpt-modal-title,#b2sell-gpt-modal-desc').on('input',updateGpt);
            $('#b2sell-meta-save').on('click',function(){
                $.post(ajaxurl,{action:'b2sell_save_seo_meta',post_id:currentId,title:$('#b2sell-meta-modal-title').val(),description:$('#b2sell-meta-modal-desc').val(),nonce:b2sellSeoNonce},function(res){
                    alert(res.success?'Guardado':'Error');
                    if(res.success){
                        var row=$('#b2sell-meta-table tr[data-id='+currentId+']');
                        row.find('.b2sell-meta-title').text($('#b2sell-meta-modal-title').val());
                        row.find('.b2sell-meta-desc').text($('#b2sell-meta-modal-desc').val());
                    }
                    $('#b2sell-meta-modal').hide();
                });
            });
            $('#b2sell-gpt-apply').on('click',function(){
                $.post(ajaxurl,{action:'b2sell_save_seo_meta',post_id:currentId,title:$('#b2sell-gpt-modal-title').val(),description:$('#b2sell-gpt-modal-desc').val(),nonce:b2sellSeoNonce},function(res){
                    alert(res.success?'Guardado':'Error');
                    if(res.success){
                        var row=$('#b2sell-meta-table tr[data-id='+currentId+']');
                        row.find('.b2sell-meta-title').text($('#b2sell-gpt-modal-title').val());
                        row.find('.b2sell-meta-desc').text($('#b2sell-gpt-modal-desc').val());
                    }
                    $('#b2sell-meta-gpt').hide();
                });
            });
            $(document).on('click','.b2sell-snippet-tab',function(){
                var v=$(this).data('view');
                var wrap=$(this).closest('.b2sell-snippet-preview');
                wrap.find('.b2sell-snippet-tab').removeClass('active');
                $(this).addClass('active');
                wrap.find('.b2sell-snippet-view').hide();
                wrap.find('.b2sell-snippet-'+v).show();
            });
        });
        </script>
        <?php
    }

    private function get_product_image_ids( $product_id ) {
        $ids = array();
        $thumbnail = get_post_thumbnail_id( $product_id );
        if ( $thumbnail ) {
            $ids[] = $thumbnail;
        }
        $gallery = get_post_meta( $product_id, '_product_image_gallery', true );
        if ( $gallery ) {
            $gallery_ids = array_filter( array_map( 'intval', explode( ',', $gallery ) ) );
            $ids         = array_merge( $ids, $gallery_ids );
        }
        return array_values( array_unique( array_filter( $ids ) ) );
    }

    private function get_product_image_alt_stats( $product_id ) {
        $ids     = $this->get_product_image_ids( $product_id );
        $total   = count( $ids );
        $missing = 0;

        foreach ( $ids as $attachment_id ) {
            $alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
            if ( '' === trim( $alt ) ) {
                $missing++;
            }
        }

        return array(
            'total'   => $total,
            'missing' => $missing,
        );
    }

    private function build_product_alt_text( $title, $index ) {
        $base = trim( wp_strip_all_tags( $title ) );
        if ( '' === $base ) {
            $base = 'Producto';
        }
        return ( 1 === $index ) ? $base : sprintf( '%s - imagen %d', $base, $index );
    }

    private function update_product_images_alt( $product_id, $allow_empty = false ) {
        $product = get_post( $product_id );
        if ( ! $product || 'product' !== $product->post_type ) {
            return new WP_Error( 'invalid_product', 'Producto no válido.' );
        }

        $ids = $this->get_product_image_ids( $product_id );
        if ( empty( $ids ) ) {
            if ( $allow_empty ) {
                return array(
                    'product_id' => $product_id,
                    'total'      => 0,
                    'updated'    => 0,
                    'missing'    => 0,
                );
            }
            return new WP_Error( 'no_images', 'El producto no tiene imágenes asociadas.' );
        }

        $index   = 1;
        $updated = 0;
        foreach ( $ids as $attachment_id ) {
            $alt_text = $this->build_product_alt_text( $product->post_title, $index );
            update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );
            $index++;
            $updated++;
        }

        return array(
            'product_id' => $product_id,
            'total'      => count( $ids ),
            'updated'    => $updated,
            'missing'    => 0,
        );
    }

    public function ajax_generate_product_alt() {
        check_ajax_referer( 'b2sell_product_alt', 'nonce' );
        $product_id = intval( $_POST['product_id'] ?? 0 );
        if ( ! $product_id ) {
            wp_send_json_error( array( 'message' => 'Producto no válido.' ) );
        }
        if ( ! current_user_can( 'edit_post', $product_id ) ) {
            wp_send_json_error( array( 'message' => 'No tienes permisos suficientes.' ) );
        }

        $result = $this->update_product_images_alt( $product_id );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error(
                array(
                    'message' => $result->get_error_message(),
                )
            );
        }

        $result['message'] = sprintf(
            'Se actualizaron %d imágenes para el producto.',
            $result['updated']
        );

        wp_send_json_success( $result );
    }

    public function ajax_generate_product_alt_batch() {
        check_ajax_referer( 'b2sell_product_alt', 'nonce' );
        if ( ! current_user_can( 'edit_products' ) && ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'No tienes permisos suficientes.' ) );
        }

        $products = get_posts(
            array(
                'post_type'   => 'product',
                'post_status' => 'publish',
                'numberposts' => -1,
                'fields'      => 'ids',
            )
        );

        if ( empty( $products ) ) {
            wp_send_json_error( array( 'message' => 'No hay productos disponibles para actualizar.' ) );
        }

        $processed     = 0;
        $total_updated = 0;
        $details       = array();

        foreach ( $products as $product_id ) {
            if ( ! current_user_can( 'edit_post', $product_id ) ) {
                continue;
            }
            $result = $this->update_product_images_alt( $product_id, true );
            if ( is_wp_error( $result ) ) {
                continue;
            }
            $processed++;
            $total_updated += $result['updated'];
            $stats = $this->get_product_image_alt_stats( $product_id );
            $details[] = array(
                'product_id' => $product_id,
                'total'      => $stats['total'],
                'missing'    => $stats['missing'],
            );
        }

        if ( 0 === $processed ) {
            wp_send_json_error( array( 'message' => 'No se pudieron actualizar los ALT de los productos.' ) );
        }

        $message = sprintf(
            'Se actualizaron ALT en %d imágenes de %d productos.',
            $total_updated,
            $processed
        );

        wp_send_json_success(
            array(
                'message'  => $message,
                'products' => $details,
            )
        );
    }



    private function render_analysis( $post_id, $section = 'content' ) {
        $post           = get_post( $post_id );
        $section        = in_array( $section, array( 'products', 'content' ), true ) ? $section : 'content';
        $stored_keyword = get_post_meta( $post_id, '_b2sell_focus_keyword', true );
        $keyword        = isset( $_POST['b2sell_keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['b2sell_keyword'] ) ) : $stored_keyword;
        $results        = false;
        $tasks_notice   = false;

        $stored_tasks = get_post_meta( $post_id, '_b2sell_seo_tasks', true );
        $tasks        = array();
        if ( is_array( $stored_tasks ) ) {
            foreach ( $stored_tasks as $task ) {
                if ( empty( $task['id'] ) ) {
                    continue;
                }
                $task_id            = preg_replace( '/[^a-z0-9]/i', '', $task['id'] );
                $tasks[ $task_id ] = array(
                    'id'        => $task_id,
                    'label'     => isset( $task['label'] ) ? sanitize_text_field( $task['label'] ) : '',
                    'completed' => ! empty( $task['completed'] ),
                );
            }
        }

        if ( isset( $_POST['b2sell_save_tasks'] ) && isset( $_POST['b2sell_tasks_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['b2sell_tasks_nonce'] ) ), 'b2sell_save_tasks' ) ) {
            $completed = isset( $_POST['b2sell_task'] ) ? (array) $_POST['b2sell_task'] : array();
            $completed = wp_unslash( $completed );
            $completed = array_map( 'sanitize_text_field', $completed );
            foreach ( $tasks as $id => &$task ) {
                $task['completed'] = in_array( $id, $completed, true );
            }
            unset( $task );
            update_post_meta( $post_id, '_b2sell_seo_tasks', array_values( $tasks ) );
            $tasks_notice = true;
        }

        $stored_results = get_post_meta( $post_id, '_b2sell_seo_last_results', true );
        if ( is_array( $stored_results ) && ! isset( $_POST['b2sell_seo_analyze'] ) ) {
            $results = $stored_results;
        }

        if ( isset( $_POST['b2sell_seo_analyze'] ) ) {
            $results = $this->perform_analysis( $post_id, $keyword );
            $history = get_post_meta( $post_id, '_b2sell_seo_history', true );
            if ( ! is_array( $history ) ) {
                $history = array();
            }
            $history[] = array(
                'date'  => current_time( 'mysql' ),
                'score' => $results['score'],
                'recommendations' => array_slice( wp_list_pluck( $results['recommendations'], 'message' ), 0, 3 ),
            );
            update_post_meta( $post_id, '_b2sell_seo_history', $history );
            update_post_meta( $post_id, '_b2sell_seo_last_results', $results );
            if ( $keyword ) {
                update_post_meta( $post_id, '_b2sell_focus_keyword', $keyword );
            } else {
                delete_post_meta( $post_id, '_b2sell_focus_keyword' );
            }
            if ( ! empty( $results['recommendations'] ) ) {
                $tasks_changed = false;
                foreach ( $results['recommendations'] as $rec ) {
                    if ( empty( $rec['message'] ) ) {
                        continue;
                    }
                    $task_id = md5( $rec['message'] );
                    $new_label = sanitize_text_field( $rec['message'] );
                    if ( ! isset( $tasks[ $task_id ] ) ) {
                        $tasks[ $task_id ] = array(
                            'id'        => $task_id,
                            'label'     => $new_label,
                            'completed' => false,
                        );
                        $tasks_changed = true;
                    } else {
                        if ( $tasks[ $task_id ]['label'] !== $new_label ) {
                            $tasks[ $task_id ]['label'] = $new_label;
                            $tasks_changed = true;
                        }
                    }
                }
                if ( $tasks_changed ) {
                    update_post_meta( $post_id, '_b2sell_seo_tasks', array_values( $tasks ) );
                }
            }
        }

        $nonce      = wp_create_nonce( 'b2sell_gpt_nonce' );
        $back_url   = add_query_arg(
            array(
                'page'    => 'b2sell-seo-analisis',
                'section' => $section,
            ),
            admin_url( 'admin.php' )
        );
        $back_label = ( 'products' === $section ) ? '« Volver a Productos' : '« Volver al listado';

        echo '<div class="wrap">';
        if ( $tasks_notice ) {
            echo '<div class="notice notice-success is-dismissible"><p>Checklist actualizado.</p></div>';
        }
        echo '<p><a href="' . esc_url( $back_url ) . '">' . esc_html( $back_label ) . '</a></p>';
        echo '<h1>Analizando: ' . esc_html( $post->post_title ) . '</h1>';
        echo '<form method="post">';
        echo '<label for="b2sell_keyword">Palabra clave principal:</label> ';
        echo '<input type="text" id="b2sell_keyword" name="b2sell_keyword" value="' . esc_attr( $keyword ) . '" /> ';
        submit_button( 'Analizar', 'primary', 'b2sell_seo_analyze' );
        echo '</form>';

        if ( $results ) {
            echo '<h2>Resultados</h2>';
            echo '<style>.b2sell-seo-green{color:#090;} .b2sell-seo-yellow{color:#e6a700;} .b2sell-seo-red{color:#c00;}</style>';
            echo '<table class="widefat fixed"><tbody>';
            foreach ( $results['metrics'] as $label => $data ) {
                echo '<tr><th>' . esc_html( $label ) . '</th><td class="b2sell-seo-' . esc_attr( $data['color'] ) . '">' . esc_html( $data['value'] ) . '</td></tr>';
            }
            echo '<tr><th>Puntaje SEO</th><td class="b2sell-seo-' . esc_attr( $results['score_color'] ) . '">' . esc_html( $results['score'] ) . '/100</td></tr>';
            echo '</tbody></table>';

            if ( ! empty( $results['recommendations'] ) ) {
                echo '<h2>Recomendaciones</h2><ul>';
                foreach ( $results['recommendations'] as $rec ) {
                    echo '<li>' . esc_html( $rec['message'] );
                    if ( ! empty( $rec['action'] ) ) {
                        echo ' <button class="button b2sell-gpt-suggest" data-action="' . esc_attr( $rec['action'] ) . '" data-post="' . esc_attr( $post_id ) . '" data-keyword="' . esc_attr( $rec['keyword'] ?? '' ) . '" data-current="' . esc_attr( $rec['current'] ?? '' ) . '">Optimizar con GPT</button>';
                    }
                    echo '</li>';
                }
                echo '</ul>';
            }

            echo '<p><button type="button" class="button" id="b2sell-toggle-detail">Ver análisis detallado</button></p>';
            echo '<div id="b2sell-analysis-detail" style="display:none;margin-top:20px;">';
            echo '<h3>Checklist de tareas SEO</h3>';
            if ( ! empty( $tasks ) ) {
                echo '<form method="post" style="margin-bottom:20px;">';
                wp_nonce_field( 'b2sell_save_tasks', 'b2sell_tasks_nonce' );
                echo '<ul class="b2sell-tasks-list" style="list-style:disc;padding-left:20px;">';
                foreach ( $tasks as $task ) {
                    $status_label = $task['completed'] ? 'Completada' : 'Pendiente';
                    $status_color = $task['completed'] ? '#008a20' : '#b54a00';
                    echo '<li style="margin-bottom:8px;"><label><input type="checkbox" name="b2sell_task[]" value="' . esc_attr( $task['id'] ) . '" ' . checked( $task['completed'], true, false ) . ' /> ' . esc_html( $task['label'] ) . '</label> <span style="font-style:italic;color:' . esc_attr( $status_color ) . ';">' . esc_html( $status_label ) . '</span></li>';
                }
                echo '</ul>';
                submit_button( 'Guardar checklist', 'secondary', 'b2sell_save_tasks', false );
                echo '</form>';
            } else {
                echo '<p>No hay tareas registradas aún. Ejecuta un análisis para generar nuevas recomendaciones.</p>';
            }
            echo '</div>';
            if ( $tasks_notice ) {
                echo '<script>jQuery(function($){$("#b2sell-analysis-detail").show();$("#b2sell-toggle-detail").text("Ocultar análisis detallado");});</script>';
            }

            echo '<div id="b2sell-gpt-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);align-items:center;justify-content:center;">'
                . '<div class="b2sell-modal-inner" style="background:#fff;padding:20px;max-width:500px;width:90%;position:relative;">'
                . '<h2 style="margin-top:0;text-align:center;">B2SELL GPT Assistant</h2>'
                . '<pre id="b2sell-gpt-text" style="white-space:pre-wrap"></pre>'
                . '<p>'
                . '<button class="button" id="b2sell-gpt-copy">Copiar</button>'
                . '<button class="button button-primary" id="b2sell-gpt-insert">Insertar</button>'
                . '<button class="button" id="b2sell-gpt-close">Cerrar</button>'
                . '</p>'
                . '<div style="text-align:center;font-size:12px;">Desarrollado por B2Sell SPA</div>'
                . '</div>'
                . '</div>';
            echo '<script>'
                . 'const b2sell_gpt_nonce = "' . esc_js( $nonce ) . '";'
                . 'jQuery(function($){'
                . '$(".b2sell-gpt-suggest").on("click",function(){'
                . 'const action=$(this).data("action");'
                . 'const post=$(this).data("post");'
                . 'const keyword=$(this).data("keyword");'
                . 'const current=$(this).data("current");'
                . 'const image=$(this).data("image");'
                . '$("#b2sell-gpt-modal").css("display","flex");'
                . '$("#b2sell-gpt-text").text("Generando...");'
                . '$("#b2sell-gpt-insert").data("action",action).data("post",post).data("image",image);'
                . '$.post(ajaxurl,{action:"b2sell_gpt_generate",gpt_action:action,keyword:keyword,paragraph:current,post_id:post,_wpnonce:b2sell_gpt_nonce},function(res){'
                . 'if(res.success){$("#b2sell-gpt-text").text(res.data.content);}else{$("#b2sell-gpt-text").text(res.data.message||res.data);}'
                . '});'
                . '});'
                . '$("#b2sell-gpt-copy").on("click",function(){'
                . 'navigator.clipboard.writeText($("#b2sell-gpt-text").text());'
                . '});'
                . '$("#b2sell-gpt-insert").on("click",function(){'
                . 'const action=$(this).data("action");'
                . 'const post=$(this).data("post");'
                . 'const image=$(this).data("image");'
                . 'const content=$("#b2sell-gpt-text").text();'
                . 'const data={action:"b2sell_gpt_insert",gpt_action:action,post_id:post,content:content,_wpnonce:b2sell_gpt_nonce};'
                . 'if(image){data.image_src=image;}'
                . '$.post(ajaxurl,data,function(res){alert(res.success?"Contenido insertado":(res.data && res.data.message?res.data.message:res.data));});'
                . '});'
                . '$("#b2sell-gpt-close").on("click",function(){'
                . '$("#b2sell-gpt-modal").hide();'
                . '});'
                . '$("#b2sell-toggle-detail").on("click",function(){'
                . 'var $detail=$("#b2sell-analysis-detail");'
                . 'var $button=$(this);'
                . '$detail.slideToggle(200,function(){'
                . '$button.text($detail.is(":visible")?"Ocultar análisis detallado":"Ver análisis detallado");'
                . '});'
                . '});'
                . '});'
                . '</script>';
            }

            // Image SEO analysis section.
            $dom = new DOMDocument();
            libxml_use_internal_errors( true );
            $dom->loadHTML( '<meta http-equiv="content-type" content="text/html; charset=utf-8" />' . $post->post_content );
            libxml_clear_errors();
            $imgs = $dom->getElementsByTagName( 'img' );
            $images = array();
            foreach ( $imgs as $img ) {
                $src   = $img->getAttribute( 'src' );
                $alt   = $img->getAttribute( 'alt' );
                $id    = attachment_url_to_postid( $src );
                $size  = 0;
                $width = 0;
                $height = 0;
                if ( $id ) {
                    $path = get_attached_file( $id );
                    if ( file_exists( $path ) ) {
                        $size = filesize( $path );
                        $meta = wp_get_attachment_metadata( $id );
                        if ( $meta ) {
                            $width  = $meta['width'] ?? 0;
                            $height = $meta['height'] ?? 0;
                        } else {
                            $info = @getimagesize( $path );
                            if ( $info ) {
                                $width  = $info[0];
                                $height = $info[1];
                            }
                        }
                    }
                }
                $images[] = array(
                    'src'    => $src,
                    'alt'    => $alt,
                    'size'   => $size,
                    'width'  => $width,
                    'height' => $height,
                );
            }
            if ( ! empty( $images ) ) {
                echo '<h2>SEO de Imágenes</h2>';
                echo '<style>.b2sell-image-green{background:#cfc;} .b2sell-image-yellow{background:#ffc;} .b2sell-image-red{background:#fcc;} .b2sell-red{color:#c00;}</style>';
                echo '<table class="widefat fixed"><thead><tr><th>Imagen</th><th>ALT actual</th><th>Tamaño/Dimensiones</th><th>Acción</th></tr></thead><tbody>';
                foreach ( $images as $im ) {
                    $size_text = $im['size'] ? size_format( $im['size'], 2 ) : '-';
                    $dim_text  = $im['width'] && $im['height'] ? $im['width'] . 'x' . $im['height'] : '-';
                    $oversize  = $im['size'] > 300 * 1024 || $im['width'] > 2000 || $im['height'] > 2000;
                    if ( '' === $im['alt'] ) {
                        $row_class = 'b2sell-image-red';
                    } elseif ( $oversize ) {
                        $row_class = 'b2sell-image-yellow';
                    } else {
                        $row_class = 'b2sell-image-green';
                    }
                    echo '<tr class="' . esc_attr( $row_class ) . '">';
                    echo '<td><img src="' . esc_url( $im['src'] ) . '" style="max-width:100px;height:auto;" /></td>';
                    echo '<td>' . esc_html( $im['alt'] ) . '</td>';
                    $size_cell = $oversize ? '<span class="b2sell-red">' . esc_html( $size_text . ' / ' . $dim_text ) . '</span><br/><em>Optimiza esta imagen para mejorar velocidad de carga</em>' : esc_html( $size_text . ' / ' . $dim_text );
                    echo '<td>' . $size_cell . '</td>';
                    if ( '' === $im['alt'] ) {
                        $keyword = sanitize_title( basename( parse_url( $im['src'], PHP_URL_PATH ) ) );
                        echo '<td><button class="button b2sell-gpt-suggest" data-action="alt" data-post="' . esc_attr( $post_id ) . '" data-keyword="' . esc_attr( $keyword ) . '" data-image="' . esc_attr( $im['src'] ) . '">Optimizar con GPT</button></td>';
                    } else {
                        echo '<td>-</td>';
                    }
                    echo '</tr>';
                }
                echo '</tbody></table>';
                echo '<p><strong>Recomendaciones generales:</strong></p><ul><li>Usar nombres de archivo descriptivos.</li><li>Incluir siempre ALT.</li><li>Comprimir imágenes pesadas.</li><li>Usar formatos modernos (WebP).</li></ul>';
            }

            $ps = $this->get_pagespeed_data( get_permalink( $post_id ) );
            if ( $ps ) {
                echo '<h2>Velocidad y rendimiento</h2>';
                echo '<div class="b2sell-speed-wrapper">';
                echo '<canvas id="b2sell-speed-score" width="200" height="120"></canvas>';
                echo '<canvas id="b2sell-speed-bars" height="120"></canvas>';
                echo '<ul>';
                echo '<li>Performance score: <span style="color:' . esc_attr( $ps['score_color'] ) . ';">' . esc_html( $ps['score'] ) . '</span></li>';
                echo '<li>TTFB: <span style="color:' . esc_attr( $ps['ttfb_color'] ) . ';">' . esc_html( $ps['ttfb'] ) . ' ms</span></li>';
                echo '<li>LCP: <span style="color:' . esc_attr( $ps['lcp_color'] ) . ';">' . esc_html( $ps['lcp'] ) . ' ms</span></li>';
                echo '<li>CLS: <span style="color:' . esc_attr( $ps['cls_color'] ) . ';">' . esc_html( $ps['cls'] ) . '</span></li>';
                echo '<li>' . esc_html( $ps['inter_label'] ) . ': <span style="color:' . esc_attr( $ps['inter_color'] ) . ';">' . esc_html( $ps['inter'] ) . ' ms</span></li>';
                echo '</ul>';
                if ( ! empty( $ps['recommendations'] ) ) {
                    echo '<h3>Recomendaciones de velocidad</h3><ul>';
                    foreach ( $ps['recommendations'] as $r ) {
                        echo '<li>' . esc_html( $r ) . '</li>';
                    }
                    echo '</ul>';
                }
                echo '</div>';
                echo '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';
                echo '<script>';
                echo 'var ctxGauge=document.getElementById("b2sell-speed-score").getContext("2d");';
                echo 'new Chart(ctxGauge,{type:"doughnut",data:{datasets:[{data:[' . esc_js( $ps['score'] ) . ',' . esc_js( 100 - $ps['score'] ) . '],backgroundColor:["' . esc_js( $ps['score_color'] ) . '","#eee"],borderWidth:0,circumference:180,rotation:270}]},options:{plugins:{legend:false},cutout:"70%"}});';
                echo 'var ctxBar=document.getElementById("b2sell-speed-bars").getContext("2d");';
                echo 'new Chart(ctxBar,{type:"bar",data:{labels:["TTFB","LCP","CLS","' . esc_js( $ps['inter_label'] ) . '"],datasets:[{data:[' . esc_js( $ps['ttfb'] ) . ',' . esc_js( $ps['lcp'] ) . ',' . esc_js( $ps['cls'] ) . ',' . esc_js( $ps['inter'] ) . '],backgroundColor:["' . esc_js( $ps['ttfb_color'] ) . '","' . esc_js( $ps['lcp_color'] ) . '","' . esc_js( $ps['cls_color'] ) . '","' . esc_js( $ps['inter_color'] ) . '"]}]},options:{scales:{y:{beginAtZero:true}}}});';
                  echo '</script>';
              }
              echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=b2sell-seo-analisis' ) ) . '">Volver al listado</a></p>';
              echo '</div>';
          }

      private function render_technical() {
        $results = $this->perform_technical_analysis();

        echo '<div class="wrap">';
        echo '<h1>SEO Técnico</h1>';
        echo '<style>.b2sell-seo-green{color:#090;} .b2sell-seo-yellow{color:#e6a700;} .b2sell-seo-red{color:#c00;}</style>';
        echo '<table class="widefat fixed"><tbody>';
        foreach ( $results['metrics'] as $label => $data ) {
            echo '<tr><th>' . esc_html( $label ) . '</th><td class="b2sell-seo-' . esc_attr( $data['color'] ) . '">' . esc_html( $data['value'] ) . '</td></tr>';
            if ( ! empty( $data['detail'] ) ) {
                echo '<tr><td colspan="2">' . esc_html( $data['detail'] ) . '</td></tr>';
            }
            if ( ! empty( $data['recommendation'] ) ) {
                echo '<tr><td colspan="2"><em>' . esc_html( $data['recommendation'] ) . '</em></td></tr>';
            }
        }
        echo '</tbody></table>';

        if ( ! empty( $results['broken_links'] ) ) {
            echo '<div class="notice notice-warning"><p>Enlaces rotos detectados:</p><ul>';
            foreach ( $results['broken_links'] as $url ) {
                echo '<li>' . esc_html( $url ) . '</li>';
            }
            echo '</ul></div>';
        }

        echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=b2sell-seo-analisis' ) ) . '">Volver al listado</a></p>';
        echo '</div>';
    }

    private function perform_technical_analysis() {
        $metrics       = array();
        $broken_links  = array();

        // robots.txt
        $robots_resp = wp_remote_get( home_url( '/robots.txt' ) );
        if ( ! is_wp_error( $robots_resp ) && 200 === wp_remote_retrieve_response_code( $robots_resp ) ) {
            $body   = strtolower( wp_remote_retrieve_body( $robots_resp ) );
            $lines  = preg_split( '/\r?\n/', $body );
            $ua     = '';
            $blocked = false;
            foreach ( $lines as $line ) {
                $line = trim( $line );
                if ( 0 === strpos( $line, 'user-agent:' ) ) {
                    $ua = trim( substr( $line, 11 ) );
                } elseif ( ( '*' === $ua || 'googlebot' === $ua ) && 0 === strpos( $line, 'disallow:' ) ) {
                    $path = trim( substr( $line, 9 ) );
                    if ( '/' === $path ) {
                        $blocked = true;
                        break;
                    }
                }
            }
            if ( $blocked ) {
                $metrics['robots.txt'] = array(
                    'value'         => 'Bloquea Googlebot',
                    'color'         => 'yellow',
                    'recommendation'=> 'Permitir el rastreo de Googlebot.',
                );
            } else {
                $metrics['robots.txt'] = array(
                    'value' => 'Permite Googlebot',
                    'color' => 'green',
                );
            }
        } else {
            $metrics['robots.txt'] = array(
                'value'         => 'No encontrado',
                'color'         => 'red',
                'recommendation'=> 'Crear un archivo robots.txt.',
            );
        }

        // sitemap.xml
        $sitemap_resp = wp_remote_get( home_url( '/sitemap.xml' ) );
        if ( ! is_wp_error( $sitemap_resp ) && 200 === wp_remote_retrieve_response_code( $sitemap_resp ) ) {
            $body = wp_remote_retrieve_body( $sitemap_resp );
            if ( false !== strpos( $body, '<urlset' ) || false !== strpos( $body, '<sitemapindex' ) ) {
                $metrics['sitemap.xml'] = array(
                    'value' => 'Válido',
                    'color' => 'green',
                );
            } else {
                $metrics['sitemap.xml'] = array(
                    'value'         => 'Formato inválido',
                    'color'         => 'yellow',
                    'recommendation'=> 'Revisar el formato del sitemap.',
                );
            }
        } else {
            $metrics['sitemap.xml'] = array(
                'value'         => 'No encontrado',
                'color'         => 'red',
                'recommendation'=> 'Crear un sitemap.xml.',
            );
        }

        // Canonical tags
        $posts        = get_posts(
            array(
                'post_type'   => array( 'post', 'page' ),
                'post_status' => 'publish',
                'numberposts' => -1,
            )
        );
        $canon_errors = array();
        foreach ( $posts as $p ) {
            $url  = get_permalink( $p->ID );
            $resp = wp_remote_get( $url );
            if ( is_wp_error( $resp ) || 200 !== wp_remote_retrieve_response_code( $resp ) ) {
                continue;
            }
            $body = wp_remote_retrieve_body( $resp );
            if ( preg_match( '/<link\s+rel=["\']canonical["\']\s+href=["\']([^"\']+)["\']/i', $body, $m ) ) {
                $href = $m[1];
                if ( ! filter_var( $href, FILTER_VALIDATE_URL ) || untrailingslashit( $href ) !== untrailingslashit( $url ) ) {
                    $canon_errors[] = $p->post_title . ' -> ' . $href;
                }
            } else {
                $canon_errors[] = $p->post_title . ' (sin canonical)';
            }
        }
        if ( empty( $canon_errors ) ) {
            $metrics['Etiquetas canonical'] = array(
                'value' => 'Correctas',
                'color' => 'green',
            );
        } else {
            $metrics['Etiquetas canonical'] = array(
                'value'         => count( $canon_errors ) . ' problemas',
                'color'         => 'red',
                'detail'        => implode( ', ', $canon_errors ),
                'recommendation'=> 'Revisar las páginas listadas.',
            );
        }

        // Broken links
        $checked = array();
        foreach ( $posts as $p ) {
            $resp = wp_remote_get( get_permalink( $p->ID ) );
            if ( is_wp_error( $resp ) ) {
                continue;
            }
            $body = wp_remote_retrieve_body( $resp );
            if ( preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\']/i', $body, $matches ) ) {
                foreach ( $matches[1] as $link ) {
                    $link = trim( $link );
                    if ( '' === $link || 0 === strpos( $link, '#' ) ) {
                        continue;
                    }
                    if ( isset( $checked[ $link ] ) ) {
                        continue;
                    }
                    $head = wp_remote_head( $link, array( 'timeout' => 5 ) );
                    if ( is_wp_error( $head ) ) {
                        continue;
                    }
                    $code = wp_remote_retrieve_response_code( $head );
                    if ( 404 === intval( $code ) ) {
                        $broken_links[]   = $link;
                        $checked[ $link ] = false;
                    } else {
                        $checked[ $link ] = true;
                    }
                }
            }
        }
        if ( empty( $broken_links ) ) {
            $metrics['Enlaces rotos'] = array(
                'value' => 'Sin enlaces rotos',
                'color' => 'green',
            );
        } else {
            $metrics['Enlaces rotos'] = array(
                'value'         => count( $broken_links ) . ' enlaces con error',
                'color'         => 'red',
                'recommendation'=> 'Corregir los enlaces listados.',
            );
        }

        return array(
            'metrics'      => $metrics,
            'broken_links' => $broken_links,
        );
    }


    private function perform_analysis( $post_id, $keyword ) {
        $post             = get_post( $post_id );
        $content          = $post->post_content;
        $metrics          = array();
        $recs             = array();
        $keyword          = is_string( $keyword ) ? trim( $keyword ) : '';
        $keyword_lower    = $keyword ? ( function_exists( 'mb_strtolower' ) ? mb_strtolower( $keyword ) : strtolower( $keyword ) ) : '';
        $keyword_slug     = $keyword ? sanitize_title( $keyword ) : '';
        $host             = parse_url( home_url(), PHP_URL_HOST );
        $score_components = array();

        if ( '' === $keyword ) {
            $recs[] = array( 'message' => 'Define una palabra clave principal para medir la optimización.' );
        }

        // Longitud de título.
        $title_len   = strlen( $post->post_title );
        if ( $title_len >= 30 && $title_len <= 60 ) {
            $title_score = 10;
            $title_color = 'green';
        } elseif ( $title_len >= 20 && $title_len <= 70 ) {
            $title_score = 5;
            $title_color = 'yellow';
            $recs[]      = array(
                'message' => 'El título debería estar entre 30 y 60 caracteres.',
                'action'  => 'title',
                'current' => $post->post_title,
                'keyword' => $keyword,
            );
        } else {
            $title_score = 0;
            $title_color = 'red';
            $recs[]      = array(
                'message' => 'Ajusta el título para mantenerlo entre 30 y 60 caracteres.',
                'action'  => 'title',
                'current' => $post->post_title,
                'keyword' => $keyword,
            );
        }
        $metrics['Longitud del título'] = array(
            'value' => $title_len . ' caracteres',
            'color' => $title_color,
        );
        $score_components[] = $title_score;

        // Meta description.
        $meta_description = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
        if ( empty( $meta_description ) ) {
            $meta_description = get_the_excerpt( $post_id );
        }
        $meta_len   = strlen( (string) $meta_description );
        if ( $meta_len >= 70 && $meta_len <= 160 ) {
            $meta_score = 10;
            $meta_color = 'green';
        } elseif ( $meta_len >= 50 && $meta_len <= 170 ) {
            $meta_score = 5;
            $meta_color = 'yellow';
            $recs[]     = array(
                'message' => 'La meta description debería tener entre 70 y 160 caracteres.',
                'action'  => 'meta',
                'current' => $meta_description,
                'keyword' => $keyword ? $keyword : $post->post_title,
            );
        } else {
            $meta_score = 0;
            $meta_color = 'red';
            $recs[]     = array(
                'message' => 'Optimiza la meta description para alcanzar entre 70 y 160 caracteres.',
                'action'  => 'meta',
                'current' => $meta_description,
                'keyword' => $keyword ? $keyword : $post->post_title,
            );
        }
        $metrics['Longitud de meta description'] = array(
            'value' => $meta_len . ' caracteres',
            'color' => $meta_color,
        );
        $score_components[] = $meta_score;

        $permalink = get_permalink( $post_id );

        // Parse content.
        $dom = new DOMDocument();
        libxml_use_internal_errors( true );
        $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $content );
        libxml_clear_errors();
        $text_content = wp_strip_all_tags( $content );
        $text_lower   = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text_content ) : strtolower( $text_content );
        $text_excerpt = wp_trim_words( $text_content, 50, '' );

        // Keyword en título/H1.
        $title_keyword_has = false;
        if ( $keyword ) {
            $sources = array( $post->post_title );
            $h1s     = $dom->getElementsByTagName( 'h1' );
            foreach ( $h1s as $h1 ) {
                $sources[] = $h1->textContent;
            }
            foreach ( $sources as $source ) {
                if ( false !== stripos( $source, $keyword ) ) {
                    $title_keyword_has = true;
                    break;
                }
            }
        }
        if ( $title_keyword_has ) {
            $title_keyword_score = 10;
            $title_keyword_color = 'green';
        } elseif ( $keyword ) {
            $title_keyword_score = 0;
            $title_keyword_color = 'red';
            $recs[]              = array(
                'message' => 'Añade la palabra clave principal en el título o encabezado H1.',
                'action'  => 'title',
                'current' => $post->post_title,
                'keyword' => $keyword,
            );
        } else {
            $title_keyword_score = 0;
            $title_keyword_color = 'red';
        }
        $metrics['Keyword principal en el título (H1)'] = array(
            'value' => $title_keyword_has ? 'Incluida' : 'No incluida',
            'color' => $title_keyword_color,
        );
        $score_components[] = $title_keyword_score;

        // Keyword en URL.
        $slug            = $permalink ? basename( untrailingslashit( $permalink ) ) : '';
        $url_has_keyword = $keyword && $keyword_slug && false !== strpos( $slug, $keyword_slug );
        if ( $url_has_keyword ) {
            $url_score = 10;
            $url_color = 'green';
        } elseif ( $keyword ) {
            $url_score = 0;
            $url_color = 'red';
            $recs[]    = array( 'message' => 'Incluye la palabra clave en el slug/URL de la página.' );
        } else {
            $url_score = 0;
            $url_color = 'red';
        }
        $metrics['Keyword en la URL'] = array(
            'value' => $url_has_keyword ? 'Incluida' : 'No incluida',
            'color' => $url_color,
        );
        $score_components[] = $url_score;

        // Keyword en meta description.
        $meta_has_keyword = $keyword && $meta_description && ( false !== stripos( $meta_description, $keyword ) );
        if ( $meta_has_keyword ) {
            $meta_keyword_score = 10;
            $meta_keyword_color = 'green';
        } elseif ( $keyword && $meta_description ) {
            $meta_keyword_score = 0;
            $meta_keyword_color = 'red';
            $recs[]             = array(
                'message' => 'Incluye la palabra clave en la meta description.',
                'action'  => 'meta',
                'current' => $meta_description,
                'keyword' => $keyword,
            );
        } elseif ( $keyword ) {
            $meta_keyword_score = 0;
            $meta_keyword_color = 'red';
        } else {
            $meta_keyword_score = 0;
            $meta_keyword_color = 'red';
        }
        $metrics['Keyword en la metadescripción'] = array(
            'value' => $meta_has_keyword ? 'Incluida' : 'No incluida',
            'color' => $meta_keyword_color,
        );
        $score_components[] = $meta_keyword_score;

        // Keyword en primer párrafo.
        $paragraphs         = $dom->getElementsByTagName( 'p' );
        $first_paragraph    = '';
        if ( $paragraphs->length > 0 ) {
            $first_paragraph = trim( $paragraphs->item( 0 )->textContent );
        }
        $first_paragraph_has = $keyword && $first_paragraph && ( false !== stripos( $first_paragraph, $keyword ) );
        if ( $first_paragraph_has ) {
            $first_paragraph_score = 10;
            $first_paragraph_color = 'green';
        } elseif ( $keyword && $first_paragraph ) {
            $first_paragraph_score = 0;
            $first_paragraph_color = 'red';
            $recs[]                = array(
                'message' => 'Incluye la palabra clave en el primer párrafo del contenido.',
                'action'  => 'rewrite',
                'current' => wp_trim_words( $first_paragraph, 50, '' ),
                'keyword' => $keyword,
            );
        } else {
            $first_paragraph_score = 0;
            $first_paragraph_color = 'red';
        }
        $metrics['Keyword en el primer párrafo del contenido'] = array(
            'value' => $first_paragraph_has ? 'Incluida' : 'No incluida',
            'color' => $first_paragraph_color,
        );
        $score_components[] = $first_paragraph_score;

        // Subtítulos.
        $subtitle_hits = 0;
        if ( $keyword ) {
            foreach ( array( 'h2', 'h3' ) as $tag ) {
                $elements = $dom->getElementsByTagName( $tag );
                foreach ( $elements as $el ) {
                    if ( false !== stripos( $el->textContent, $keyword ) ) {
                        $subtitle_hits++;
                    }
                }
            }
        }
        if ( $subtitle_hits > 0 ) {
            $subtitle_score = 10;
            $subtitle_color = 'green';
        } elseif ( $keyword ) {
            $subtitle_score = 0;
            $subtitle_color = 'red';
            $recs[]         = array( 'message' => 'Incluye la palabra clave en subtítulos (H2 o H3).' );
        } else {
            $subtitle_score = 0;
            $subtitle_color = 'red';
        }
        $metrics['Keyword en subtítulos (H2, H3...)'] = array(
            'value' => $subtitle_hits > 0 ? $subtitle_hits . ' subtítulo(s)' : 'No presente',
            'color' => $subtitle_color,
        );
        $score_components[] = $subtitle_score;

        // Enlaces.
        $internal_links    = 0;
        $external_links    = 0;
        $relevant_internal = 0;
        $trusted_external  = 0;
        $links             = $dom->getElementsByTagName( 'a' );
        foreach ( $links as $a ) {
            $href = trim( $a->getAttribute( 'href' ) );
            if ( '' === $href ) {
                continue;
            }
            $href_host = parse_url( $href, PHP_URL_HOST );
            $scheme    = parse_url( $href, PHP_URL_SCHEME );
            $anchor    = trim( preg_replace( '/\s+/', ' ', $a->textContent ) );
            if ( ! $href_host || $href_host === $host ) {
                $internal_links++;
                if ( '' !== $anchor ) {
                    $anchor_words = str_word_count( $anchor );
                    if ( ( $keyword && false !== stripos( $anchor, $keyword ) ) || $anchor_words >= 2 ) {
                        $relevant_internal++;
                    }
                }
            } else {
                $external_links++;
                if ( 'https' === strtolower( (string) $scheme ) ) {
                    $trusted_external++;
                }
            }
        }
        $link_score = ( $internal_links > 0 ? 5 : 0 ) + ( $external_links > 0 ? 5 : 0 );
        $links_color = ( 10 === $link_score ) ? 'green' : ( ( $link_score >= 5 ) ? 'yellow' : 'red' );
        if ( $internal_links === 0 ) {
            $recs[] = array( 'message' => 'Agrega enlaces internos que refuercen el contenido.' );
        }
        if ( $external_links === 0 ) {
            $recs[] = array( 'message' => 'Añade al menos un enlace externo a una fuente confiable.' );
        }
        $metrics['Enlaces internos/externos'] = array(
            'value' => $internal_links . ' internos / ' . $external_links . ' externos',
            'color' => $links_color,
        );
        $score_components[] = $link_score;

        if ( $internal_links === 0 ) {
            $relevant_internal_score = 0;
            $relevant_internal_color = 'red';
        } elseif ( $relevant_internal > 0 ) {
            $ratio = $internal_links ? $relevant_internal / $internal_links : 0;
            if ( $ratio >= 0.5 ) {
                $relevant_internal_score = 10;
                $relevant_internal_color = 'green';
            } else {
                $relevant_internal_score = 5;
                $relevant_internal_color = 'yellow';
                $recs[]                  = array( 'message' => 'Mejora el anchor text de los enlaces internos para que sea más descriptivo.' );
            }
        } else {
            $relevant_internal_score = 0;
            $relevant_internal_color = 'red';
            $recs[]                  = array( 'message' => 'Utiliza anchor text descriptivo en los enlaces internos.' );
        }
        $metrics['Presencia de enlaces internos con anchor text relevante'] = array(
            'value' => $internal_links ? $relevant_internal . '/' . $internal_links : 'Sin enlaces',
            'color' => $relevant_internal_color,
        );
        $score_components[] = $relevant_internal_score;

        if ( $external_links === 0 ) {
            $trusted_external_score = 0;
            $trusted_external_color = 'red';
        } elseif ( $trusted_external > 0 ) {
            $trusted_external_score = 10;
            $trusted_external_color = 'green';
        } else {
            $trusted_external_score = 5;
            $trusted_external_color = 'yellow';
            $recs[]                 = array( 'message' => 'Asegúrate de que los enlaces externos apunten a sitios seguros (HTTPS).' );
        }
        $metrics['Presencia de enlaces externos a fuentes confiables'] = array(
            'value' => $external_links ? $trusted_external . '/' . $external_links : 'Sin enlaces',
            'color' => $trusted_external_color,
        );
        $score_components[] = $trusted_external_score;

        // Imágenes y ALT.
        $images           = $dom->getElementsByTagName( 'img' );
        $img_total        = $images->length;
        $img_with_alt     = 0;
        $img_keyword_alt  = 0;
        $optimized_images = 0;
        foreach ( $images as $img ) {
            $alt        = trim( $img->getAttribute( 'alt' ) );
            $src        = $img->getAttribute( 'src' );
            $alt_lower  = function_exists( 'mb_strtolower' ) ? mb_strtolower( $alt ) : strtolower( $alt );
            $filename   = $src ? wp_basename( parse_url( $src, PHP_URL_PATH ) ) : '';
            $file_slug  = $filename ? sanitize_title( pathinfo( $filename, PATHINFO_FILENAME ) ) : '';
            if ( '' !== $alt ) {
                $img_with_alt++;
            }
            if ( $keyword && '' !== $alt && false !== strpos( $alt_lower, $keyword_lower ) ) {
                $img_keyword_alt++;
            }
            if ( $keyword && '' !== $alt && $keyword_slug && $file_slug && false !== strpos( $file_slug, $keyword_slug ) && false !== strpos( $alt_lower, $keyword_lower ) ) {
                $optimized_images++;
            }
        }
        if ( 0 === $img_total || $img_with_alt === $img_total ) {
            $alt_score = 10;
            $alt_color = 'green';
        } elseif ( $img_with_alt > 0 ) {
            $alt_score = 5;
            $alt_color = 'yellow';
            $recs[]    = array(
                'message' => 'Agrega atributos ALT a todas las imágenes.',
                'action'  => 'alt',
                'current' => $post->post_title,
                'keyword' => $keyword ? $keyword : $post->post_title,
            );
        } else {
            $alt_score = 0;
            $alt_color = 'red';
            $recs[]    = array(
                'message' => 'Incluye atributos ALT descriptivos en las imágenes.',
                'action'  => 'alt',
                'current' => $post->post_title,
                'keyword' => $keyword ? $keyword : $post->post_title,
            );
        }
        $metrics['Imágenes con ALT'] = array(
            'value' => $img_with_alt . '/' . $img_total,
            'color' => $alt_color,
        );
        $score_components[] = $alt_score;

        if ( 0 === $img_total ) {
            $alt_keyword_score = 10;
            $alt_keyword_color = 'green';
            $alt_keyword_value = 'Sin imágenes';
        } elseif ( $img_keyword_alt > 0 ) {
            $ratio = $img_total ? $img_keyword_alt / $img_total : 0;
            if ( $ratio >= 0.5 ) {
                $alt_keyword_score = 10;
                $alt_keyword_color = 'green';
            } else {
                $alt_keyword_score = 5;
                $alt_keyword_color = 'yellow';
                $recs[]            = array( 'message' => 'Añade la palabra clave al ALT de más imágenes relevantes.' );
            }
            $alt_keyword_value = $img_keyword_alt . '/' . $img_total;
        } else {
            $alt_keyword_score = 0;
            $alt_keyword_color = 'red';
            $alt_keyword_value = '0/' . $img_total;
            if ( $keyword ) {
                $recs[] = array( 'message' => 'Incluye la palabra clave en los atributos ALT de las imágenes principales.' );
            }
        }
        $metrics['Palabra clave en atributos ALT de imágenes'] = array(
            'value' => $alt_keyword_value,
            'color' => $alt_keyword_color,
        );
        $score_components[] = $alt_keyword_score;

        if ( 0 === $img_total ) {
            $optimized_score = 10;
            $optimized_color = 'green';
            $optimized_value = 'Sin imágenes';
        } elseif ( $optimized_images > 0 ) {
            $ratio = $img_total ? $optimized_images / $img_total : 0;
            if ( $ratio >= 0.5 ) {
                $optimized_score = 10;
                $optimized_color = 'green';
            } else {
                $optimized_score = 5;
                $optimized_color = 'yellow';
                $recs[]          = array( 'message' => 'Optimiza más nombres de archivo y ALT con la palabra clave.' );
            }
            $optimized_value = $optimized_images . '/' . $img_total;
        } else {
            $optimized_score = 0;
            $optimized_color = 'red';
            $optimized_value = '0/' . $img_total;
            $recs[]          = array( 'message' => 'Renombra archivos e incluye la palabra clave en ALT para imágenes clave.' );
        }
        $metrics['Imágenes con nombre de archivo y ALT optimizados'] = array(
            'value' => $optimized_value,
            'color' => $optimized_color,
        );
        $score_components[] = $optimized_score;

        // Schema.org.
        $schema  = false;
        $scripts = $dom->getElementsByTagName( 'script' );
        foreach ( $scripts as $script ) {
            if ( 'application/ld+json' === strtolower( $script->getAttribute( 'type' ) ) ) {
                $schema = true;
                break;
            }
        }
        if ( ! $schema && false !== strpos( $content, 'schema.org' ) ) {
            $schema = true;
        }
        $schema_score = $schema ? 10 : 0;
        $schema_color = $schema ? 'green' : 'red';
        if ( ! $schema ) {
            $recs[] = array( 'message' => 'Agrega datos estructurados (schema.org) al contenido.' );
        }
        $metrics['Uso de schema.org'] = array(
            'value' => $schema ? 'Sí' : 'No',
            'color' => $schema_color,
        );
        $score_components[] = $schema_score;

        // Densidad de palabra clave.
        $words         = str_word_count( $text_lower, 1 );
        $total_words   = count( $words );
        $keyword_count = 0;
        if ( $keyword_lower ) {
            foreach ( $words as $w ) {
                if ( $w === $keyword_lower ) {
                    $keyword_count++;
                }
            }
        }
        $density = $total_words ? ( $keyword_count / $total_words ) * 100 : 0;
        if ( $keyword ) {
            if ( $density >= 1 && $density <= 2.5 ) {
                $density_score = 10;
                $density_color = 'green';
            } elseif ( $density >= 0.8 && $density <= 3 ) {
                $density_score = 5;
                $density_color = 'yellow';
                $recs[]        = array(
                    'message' => 'Ajusta la densidad de palabras clave entre 1% y 2.5%.',
                    'action'  => 'rewrite',
                    'current' => $text_excerpt,
                    'keyword' => $keyword,
                );
            } else {
                $density_score = 0;
                $density_color = 'red';
                $recs[]        = array(
                    'message' => 'La densidad de la palabra clave está fuera de rango (1%-2.5%).',
                    'action'  => 'rewrite',
                    'current' => $text_excerpt,
                    'keyword' => $keyword,
                );
            }
        } else {
            $density_score = 0;
            $density_color = 'red';
        }
        $metrics['Densidad de la palabra clave'] = array(
            'value' => round( $density, 2 ) . '%',
            'color' => $density_color,
        );
        $score_components[] = $density_score;

        // LSI keywords.
        $lsi_candidates = array();
        $yoast_synonyms = get_post_meta( $post_id, '_yoast_wpseo_keywordsynonyms', true );
        if ( ! empty( $yoast_synonyms ) ) {
            $lsi_candidates = array_merge( $lsi_candidates, array_map( 'trim', explode( ',', $yoast_synonyms ) ) );
        }
        $lsi_candidates = array_merge(
            $lsi_candidates,
            wp_get_post_terms( $post_id, 'category', array( 'fields' => 'names' ) ),
            wp_get_post_tags( $post_id, array( 'fields' => 'names' ) )
        );
        $lsi_candidates = array_filter( array_unique( array_map( 'strtolower', $lsi_candidates ) ) );
        $lsi_found      = array();
        foreach ( $lsi_candidates as $lsi ) {
            if ( '' !== $lsi && false !== strpos( $text_lower, $lsi ) ) {
                $lsi_found[] = $lsi;
            }
        }
        if ( empty( $lsi_candidates ) ) {
            $lsi_score = 5;
            $lsi_color = 'yellow';
            $lsi_value = 'Sin sinónimos definidos';
            $recs[]    = array( 'message' => 'Añade sinónimos o palabras clave relacionadas (tags o categorías).' );
        } elseif ( count( $lsi_found ) >= max( 1, ceil( count( $lsi_candidates ) / 2 ) ) ) {
            $lsi_score = 10;
            $lsi_color = 'green';
            $lsi_value = count( $lsi_found ) . '/' . count( $lsi_candidates ) . ' detectadas';
        } elseif ( ! empty( $lsi_found ) ) {
            $lsi_score = 5;
            $lsi_color = 'yellow';
            $lsi_value = count( $lsi_found ) . '/' . count( $lsi_candidates ) . ' detectadas';
            $recs[]    = array( 'message' => 'Incorpora más sinónimos o keywords relacionadas en el contenido.' );
        } else {
            $lsi_score = 0;
            $lsi_color = 'red';
            $lsi_value = '0/' . count( $lsi_candidates ) . ' detectadas';
            $recs[]    = array( 'message' => 'Incluye sinónimos o palabras clave relacionadas (LSI keywords).' );
        }
        $metrics['Presencia de sinónimos o keywords relacionadas (LSI)'] = array(
            'value' => $lsi_value,
            'color' => $lsi_color,
        );
        $score_components[] = $lsi_score;

        // Longitud del contenido.
        if ( $total_words >= 800 ) {
            $length_score = 10;
            $length_color = 'green';
        } elseif ( $total_words >= 400 ) {
            $length_score = 5;
            $length_color = 'yellow';
            $recs[]       = array( 'message' => 'Amplía el contenido para superar las 800 palabras si es pertinente.' );
        } else {
            $length_score = 0;
            $length_color = 'red';
            $recs[]       = array( 'message' => 'Añade más contenido relevante; actualmente es muy corto.' );
        }
        $metrics['Longitud del contenido (palabras totales)'] = array(
            'value' => $total_words . ' palabras',
            'color' => $length_color,
        );
        $score_components[] = $length_score;

        // Legibilidad.
        $sentences = preg_split( '/[\.!?]+/u', $text_content, -1, PREG_SPLIT_NO_EMPTY );
        if ( ! is_array( $sentences ) ) {
            $sentences = array();
        }
        $sentence_words_total = 0;
        foreach ( $sentences as $sentence ) {
            $sentence_words_total += str_word_count( $sentence );
        }
        $sentence_count        = count( $sentences );
        $avg_sentence_length   = $sentence_count ? $sentence_words_total / $sentence_count : 0;
        $readability           = $this->flesch_reading_ease( $text_content );
        if ( $readability >= 60 && $avg_sentence_length <= 20 ) {
            $read_score = 10;
            $read_color = 'green';
        } elseif ( $readability >= 50 && $avg_sentence_length <= 25 ) {
            $read_score = 5;
            $read_color = 'yellow';
            $recs[]     = array( 'message' => 'Mejora la legibilidad con párrafos más cortos y frases simples.' );
        } else {
            $read_score = 0;
            $read_color = 'red';
            $recs[]     = array( 'message' => 'La legibilidad es baja; simplifica frases y divide párrafos largos.' );
        }
        $metrics['Legibilidad del texto'] = array(
            'value' => 'Índice Flesch: ' . round( $readability, 2 ) . ' | Palabras/oración: ' . round( $avg_sentence_length, 2 ),
            'color' => $read_color,
        );
        $score_components[] = $read_score;

        // Listas y tablas.
        $list_count  = $dom->getElementsByTagName( 'ul' )->length + $dom->getElementsByTagName( 'ol' )->length;
        $table_count = $dom->getElementsByTagName( 'table' )->length;
        if ( ( $list_count + $table_count ) > 0 ) {
            $lists_score = 10;
            $lists_color = 'green';
        } else {
            $lists_score = 0;
            $lists_color = 'red';
            $recs[]      = array( 'message' => 'Agrega listas o tablas para mejorar la escaneabilidad del contenido.' );
        }
        $metrics['Uso de listas y tablas'] = array(
            'value' => $list_count . ' listas / ' . $table_count . ' tablas',
            'color' => $lists_color,
        );
        $score_components[] = $lists_score;

        // Tiempo de carga y canonical.
        $start    = microtime( true );
        $response = wp_remote_get( $permalink );
        $load_time = round( microtime( true ) - $start, 2 );
        $body      = '';
        if ( is_wp_error( $response ) ) {
            $load_time = 0;
        } else {
            $body = wp_remote_retrieve_body( $response );
        }
        if ( $load_time > 0 && $load_time <= 2 ) {
            $speed_score = 10;
            $speed_color = 'green';
        } elseif ( $load_time > 0 && $load_time <= 4 ) {
            $speed_score = 5;
            $speed_color = 'yellow';
            $recs[]      = array( 'message' => 'Mejora el tiempo de carga por debajo de 2 segundos.' );
        } else {
            $speed_score = 0;
            $speed_color = 'red';
            $recs[]      = array( 'message' => 'El tiempo de carga es alto; optimiza imágenes y caché.' );
        }
        $metrics['Tiempo de carga'] = array(
            'value' => $load_time ? $load_time . 's' : 'No disponible',
            'color' => $speed_color,
        );
        $score_components[] = $speed_score;

        $canonical_score = 0;
        $canonical_color = 'red';
        $canonical_value = 'No detectado';
        if ( is_wp_error( $response ) ) {
            $canonical_color = 'yellow';
            $canonical_value = 'No se pudo comprobar';
            $canonical_score = 5;
            $recs[]          = array( 'message' => 'No fue posible verificar la etiqueta canonical.' );
        } elseif ( $body && preg_match( '/<link\s+rel=["\']canonical["\']\s+href=["\']([^"\']+)["\']/i', $body, $m ) ) {
            $canonical_url   = $m[1];
            $canonical_value = $canonical_url;
            if ( filter_var( $canonical_url, FILTER_VALIDATE_URL ) && untrailingslashit( $canonical_url ) === untrailingslashit( $permalink ) ) {
                $canonical_score = 10;
                $canonical_color = 'green';
            } else {
                $canonical_score = 0;
                $canonical_color = 'red';
                $recs[]          = array( 'message' => 'Revisa la etiqueta canonical, no coincide con la URL actual.' );
            }
        } else {
            $recs[] = array( 'message' => 'Agrega una etiqueta canonical que apunte a la URL principal.' );
        }
        $metrics['Canonical tag correctamente configurado'] = array(
            'value' => $canonical_value,
            'color' => $canonical_color,
        );
        $score_components[] = $canonical_score;

        // Canibalización.
        $cannibal_posts = array();
        if ( $keyword ) {
            $others = get_posts(
                array(
                    'post_type'      => array( 'post', 'page', 'product' ),
                    'post_status'    => 'publish',
                    'posts_per_page' => -1,
                    'post__not_in'   => array( $post_id ),
                    'meta_key'       => '_b2sell_focus_keyword',
                    'meta_value'     => $keyword,
                )
            );
            foreach ( $others as $other ) {
                $cannibal_posts[] = $other->post_title;
            }
        }
        if ( $keyword && ! empty( $cannibal_posts ) ) {
            $cannibal_score = 0;
            $cannibal_color = 'red';
            $cannibal_value = implode( ', ', $cannibal_posts );
            $recs[]         = array( 'message' => 'Evita la canibalización: ajusta o enlaza con estas páginas: ' . $cannibal_value . '.' );
        } elseif ( $keyword ) {
            $cannibal_score = 10;
            $cannibal_color = 'green';
            $cannibal_value = 'Sin conflictos';
        } else {
            $cannibal_score = 5;
            $cannibal_color = 'yellow';
            $cannibal_value = 'Define una palabra clave para evaluar';
        }
        $metrics['Evitar canibalización'] = array(
            'value' => $cannibal_value,
            'color' => $cannibal_color,
        );
        $score_components[] = $cannibal_score;

        // Calcular puntaje final.
        $max_score   = count( $score_components ) * 10;
        $total_score = array_sum( $score_components );
        $final_score = $max_score ? round( ( $total_score / $max_score ) * 100 ) : 0;
        $score_color = ( $final_score >= 80 ) ? 'green' : ( ( $final_score >= 60 ) ? 'yellow' : 'red' );

        return array(
            'score'           => $final_score,
            'score_color'     => $score_color,
            'metrics'         => $metrics,
            'recommendations' => $recs,
        );
    }


    private function get_pagespeed_data( $url ) {
        $api_key = get_option( 'b2sell_pagespeed_api_key', '' );
        $api_url = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url=' . rawurlencode( $url ) . '&strategy=mobile';
        if ( $api_key ) {
            $api_url .= '&key=' . $api_key;
        }
        $response = wp_remote_get( $api_url, array( 'timeout' => 30 ) );
        if ( is_wp_error( $response ) ) {
            return false;
        }
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $data ) || empty( $data['lighthouseResult'] ) ) {
            return false;
        }
        $lh     = $data['lighthouseResult'];
        $audits = $lh['audits'];
        $score  = isset( $lh['categories']['performance']['score'] ) ? intval( $lh['categories']['performance']['score'] * 100 ) : 0;
        $score_color = $this->color_high( $score, 90, 50 );
        $ttfb   = isset( $audits['server-response-time']['numericValue'] ) ? round( $audits['server-response-time']['numericValue'] ) : 0;
        $ttfb_color = $this->color_low( $ttfb, 200, 600 );
        $lcp    = isset( $audits['largest-contentful-paint']['numericValue'] ) ? round( $audits['largest-contentful-paint']['numericValue'] ) : 0;
        $lcp_color = $this->color_low( $lcp, 2500, 4000 );
        $cls    = isset( $audits['cumulative-layout-shift']['numericValue'] ) ? round( $audits['cumulative-layout-shift']['numericValue'], 2 ) : 0;
        $cls_color = $this->color_low( $cls, 0.1, 0.25 );
        $field  = $data['loadingExperience']['metrics'] ?? array();
        if ( isset( $field['INTERACTION_TO_NEXT_PAINT']['percentile'] ) ) {
            $inter       = round( $field['INTERACTION_TO_NEXT_PAINT']['percentile'] );
            $inter_label = 'INP';
            $inter_color = $this->color_low( $inter, 200, 500 );
        } else {
            $inter       = isset( $field['FIRST_INPUT_DELAY_MS']['percentile'] ) ? round( $field['FIRST_INPUT_DELAY_MS']['percentile'] ) : 0;
            $inter_label = 'FID';
            $inter_color = $this->color_low( $inter, 100, 300 );
        }
        $recs = array();
        if ( $score < 90 ) {
            $recs[] = 'Mejora el rendimiento general para incrementar el puntaje de performance.';
        }
        if ( $ttfb > 600 ) {
            $recs[] = 'Reduce el tiempo de respuesta del servidor (TTFB).';
        }
        if ( $lcp > 4000 ) {
            $recs[] = 'Optimiza imágenes grandes para mejorar el LCP.';
        }
        if ( $cls > 0.25 ) {
            $recs[] = 'Evita cambios de diseño inesperados para reducir el CLS.';
        }
        if ( ( 'INP' === $inter_label && $inter > 500 ) || ( 'FID' === $inter_label && $inter > 300 ) ) {
            $recs[] = 'Reduce JavaScript bloqueante para mejorar la interactividad.';
        }
        return array(
            'score'          => $score,
            'score_color'    => $score_color,
            'ttfb'           => $ttfb,
            'ttfb_color'     => $ttfb_color,
            'lcp'            => $lcp,
            'lcp_color'      => $lcp_color,
            'cls'            => $cls,
            'cls_color'      => $cls_color,
            'inter'          => $inter,
            'inter_color'    => $inter_color,
            'inter_label'    => $inter_label,
            'recommendations'=> $recs,
        );
    }

    private function color_low( $value, $good, $ok ) {
        if ( $value <= $good ) {
            return '#090';
        }
        if ( $value <= $ok ) {
            return '#e6a700';
        }
        return '#c00';
    }

    private function color_high( $value, $good, $ok ) {
        if ( $value >= $good ) {
            return '#090';
        }
        if ( $value >= $ok ) {
            return '#e6a700';
        }
        return '#c00';
    }

    private function flesch_reading_ease( $text ) {
        $sentences = max( 1, preg_match_all( '/[.!?]/', $text, $m ) );
        $words     = str_word_count( $text );
        $syllables = preg_split( '/[^aeiouy]+/i', $text );
        $syllables = array_filter( $syllables );
        $syllables = count( $syllables );
        $words     = max( 1, $words );
        $sentences = max( 1, $sentences );
        return 206.835 - ( 1.015 * ( $words / $sentences ) ) - ( 84.6 * ( $syllables / $words ) );
    }

    public function get_technical_summary() {
        $results = $this->perform_technical_analysis();

        $recs        = array();
        $score_total = 0;
        $count       = 0;
        foreach ( $results['metrics'] as $metric ) {
            $count++;
            $score_total += ( 'green' === $metric['color'] ) ? 100 : ( ( 'yellow' === $metric['color'] ) ? 50 : 0 );
            if ( ! empty( $metric['recommendation'] ) ) {
                $recs[] = $metric['recommendation'];
            }
        }

        $ps_score = 0;
        $ps_color = 'red';
        $ps       = $this->get_pagespeed_data( home_url() );
        if ( $ps ) {
            $ps_score   = $ps['score'];
            $ps_color   = ( $ps_score >= 80 ) ? 'green' : ( ( $ps_score >= 50 ) ? 'yellow' : 'red' );
            $count++;
            $score_total += ( 'green' === $ps_color ) ? 100 : ( ( 'yellow' === $ps_color ) ? 50 : 0 );
            if ( ! empty( $ps['recommendations'] ) ) {
                $recs = array_merge( $recs, $ps['recommendations'] );
            }
        }

        $broken_count = count( $results['broken_links'] );
        if ( $broken_count ) {
            $recs[] = 'Se detectaron ' . $broken_count . ' enlaces rotos.';
        }

        $score = $count ? round( $score_total / $count ) : 0;

        $metrics = array(
            'robots'   => array(
                'label' => 'robots.txt',
                'value' => $results['metrics']['robots.txt']['value'],
                'color' => $results['metrics']['robots.txt']['color'],
            ),
            'sitemap'  => array(
                'label' => 'sitemap.xml',
                'value' => $results['metrics']['sitemap.xml']['value'],
                'color' => $results['metrics']['sitemap.xml']['color'],
            ),
            'pagespeed'=> array(
                'label' => 'PageSpeed score',
                'value' => $ps_score,
                'color' => $ps_color,
            ),
            'broken'   => array(
                'label' => 'Enlaces rotos',
                'value' => $broken_count,
                'color' => $broken_count ? 'red' : 'green',
            ),
        );

        return array(
            'score'           => $score,
            'metrics'         => $metrics,
            'recommendations' => $recs,
        );
    }

    public function get_images_summary() {
        $posts        = get_posts(
            array(
                'post_type'   => array( 'post', 'page' ),
                'post_status' => 'publish',
                'numberposts' => -1,
            )
        );

        $total   = 0;
        $missing = 0;
        $heavy   = 0;
        foreach ( $posts as $p ) {
            $dom = new DOMDocument();
            libxml_use_internal_errors( true );
            $dom->loadHTML( '<meta http-equiv="content-type" content="text/html; charset=utf-8" />' . $p->post_content );
            libxml_clear_errors();
            $imgs = $dom->getElementsByTagName( 'img' );
            foreach ( $imgs as $img ) {
                $total++;
                $alt = $img->getAttribute( 'alt' );
                if ( '' === trim( $alt ) ) {
                    $missing++;
                }
                $src = $img->getAttribute( 'src' );
                $id  = attachment_url_to_postid( $src );
                $size = 0;
                $width = 0;
                $height = 0;
                if ( $id ) {
                    $path = get_attached_file( $id );
                    if ( $path && file_exists( $path ) ) {
                        $size = filesize( $path );
                        $meta = wp_get_attachment_metadata( $id );
                        if ( $meta ) {
                            $width  = $meta['width'] ?? 0;
                            $height = $meta['height'] ?? 0;
                        } else {
                            $info = @getimagesize( $path );
                            if ( $info ) {
                                $width  = $info[0];
                                $height = $info[1];
                            }
                        }
                    }
                }
                if ( $size > 300 * 1024 || $width > 2000 || $height > 2000 ) {
                    $heavy++;
                }
            }
        }

        $score = $total ? round( ( ( ( $total - $missing ) / $total ) * 0.6 + ( ( $total - $heavy ) / $total ) * 0.4 ) * 100 ) : 0;
        $color = ( $score >= 80 ) ? 'green' : ( ( $score >= 50 ) ? 'yellow' : 'red' );

        $recs = array();
        if ( $missing ) {
            $recs[] = $missing . ' imágenes sin ALT.';
        }
        if ( $heavy ) {
            $recs[] = $heavy . ' imágenes exceden peso recomendado.';
        }

        return array(
            'score'           => $score,
            'color'           => $color,
            'total'           => $total,
            'missing_alt'     => $missing,
            'oversized'       => $heavy,
            'recommendations' => $recs,
        );
    }

    /**
     * Retrieve historical results of global analyses.
     *
     * @return array
     */
    public function get_dashboard_history() {
        $history = get_option( 'b2sell_seo_dashboard_history', array() );
        return is_array( $history ) ? $history : array();
    }

    public function run_full_site_analysis() {
        $posts = get_posts(
            array(
                'post_type'   => array( 'post', 'page' ),
                'post_status' => 'publish',
                'numberposts' => -1,
            )
        );

        $total_onpage = 0;
        $analyses     = 0;
        $recs         = array();

        foreach ( $posts as $p ) {
            $result = $this->perform_analysis( $p->ID, '' );

            $history = get_post_meta( $p->ID, '_b2sell_seo_history', true );
            if ( ! is_array( $history ) ) {
                $history = array();
            }
            $history[] = array(
                'date'           => current_time( 'Y-m-d' ),
                'score'          => $result['score'],
                'recommendations'=> array_map(
                    function( $r ) {
                        return $r['message'];
                    },
                    $result['recommendations']
                ),
            );
            update_post_meta( $p->ID, '_b2sell_seo_history', $history );

            $total_onpage += intval( $result['score'] );
            $analyses++;
            if ( ! empty( $result['recommendations'] ) ) {
                $recs = array_merge(
                    $recs,
                    array_map(
                        function( $r ) {
                            return $r['message'];
                        },
                        $result['recommendations']
                    )
                );
            }
        }

        $onpage_avg = $analyses ? round( $total_onpage / $analyses ) : 0;

        $technical = $this->get_technical_summary();
        $images    = $this->get_images_summary();

        $recs = array_merge( $recs, $technical['recommendations'], $images['recommendations'] );
        $recs = array_unique( $recs );
        $recs = array_slice( $recs, 0, 5 );

        $global_score = round( $onpage_avg * 0.4 + $technical['score'] * 0.4 + $images['score'] * 0.2 );
        $score_color  = ( $global_score >= 80 ) ? 'green' : ( ( $global_score >= 50 ) ? 'yellow' : 'red' );

        $cache = array(
            'timestamp'       => current_time( 'mysql' ),
            'onpage_avg'      => $onpage_avg,
            'technical'       => $technical,
            'images'          => $images,
            'global_score'    => $global_score,
            'score_color'     => $score_color,
            'recommendations' => $recs,
        );
        update_option( 'b2sell_seo_dashboard_cache', $cache );

        // Store history of global analyses.
        $history   = $this->get_dashboard_history();
        $history[] = array(
            'date'         => current_time( 'Y-m-d' ),
            'global_score' => $global_score,
            'onpage'       => $onpage_avg,
            'technical'    => $technical['score'],
            'images'       => $images['score'],
        );
        // Keep only the latest 50 entries to avoid unlimited growth.
        if ( count( $history ) > 50 ) {
            $history = array_slice( $history, -50 );
        }
        update_option( 'b2sell_seo_dashboard_history', $history );

        return $cache;
    }
}
