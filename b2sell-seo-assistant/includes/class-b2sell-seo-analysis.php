<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class B2Sell_SEO_Analysis {

    public function render_admin_page() {
        if ( isset( $_GET['post_id'] ) ) {
            $this->render_analysis( intval( $_GET['post_id'] ) );
        } elseif ( isset( $_GET['technical'] ) ) {
            $this->render_technical();
        } else {
            $this->render_list();
        }
    }

    private function render_list() {
        $posts      = get_posts(
            array(
                'post_type'   => array( 'post', 'page' ),
                'post_status' => 'publish',
                'numberposts' => -1,
            )
        );

        $low_score = isset( $_GET['low_score'] );
        $order     = isset( $_GET['order'] ) ? sanitize_text_field( $_GET['order'] ) : '';

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

        echo '<div class="wrap">';
        echo '<h1>Análisis SEO</h1>';
        echo '<form method="get" style="margin-bottom:15px;">';
        echo '<input type="hidden" name="page" value="b2sell-seo-analisis" />';
        echo '<label><input type="checkbox" name="low_score" value="1" ' . checked( $low_score, true, false ) . ' /> Mostrar solo puntaje bajo (&lt;60)</label> ';
        echo '<select name="order">';
        echo '<option value="">Ordenar...</option>';
        echo '<option value="date" ' . selected( $order, 'date', false ) . '>Fecha de análisis</option>';
        echo '<option value="score" ' . selected( $order, 'score', false ) . '>Puntaje de mayor a menor</option>';
        echo '</select> ';
        submit_button( 'Filtrar', '', '', false );
        echo '</form>';

        echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=b2sell-seo-analisis&technical=1' ) ) . '">SEO Técnico</a></p>';

        echo '<p><button id="b2sell-export-csv" class="button">Exportar CSV</button> ';
        echo '<button id="b2sell-export-pdf" class="button">Exportar PDF</button></p>';

        echo '<table id="b2sell-seo-table" class="widefat fixed">';
        echo '<thead><tr><th>Título</th><th>Tipo</th><th>Último análisis</th><th>Puntaje</th><th></th></tr></thead><tbody>';
        foreach ( $rows as $row ) {
            $link    = admin_url( 'admin.php?page=b2sell-seo-analisis&post_id=' . $row['ID'] );
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
        echo '</div>';
    }

    private function render_analysis( $post_id ) {
        $post    = get_post( $post_id );
        $keyword = isset( $_POST['b2sell_keyword'] ) ? sanitize_text_field( $_POST['b2sell_keyword'] ) : '';
        $results = false;

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
        }

        $nonce = wp_create_nonce( 'b2sell_gpt_nonce' );
        echo '<div class="wrap">';
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
                        echo ' <button class="button b2sell-gpt-suggest" data-action="' . esc_attr( $rec['action'] ) . '" data-post="' . esc_attr( $post_id ) . '" data-keyword="' . esc_attr( $rec['keyword'] ?? '' ) . '" data-current="' . esc_attr( $rec['current'] ?? '' ) . '">Sugerencia con GPT</button>';
                    }
                    echo '</li>';
                }
                echo '</ul>';
                echo '<div id="b2sell-gpt-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);align-items:center;justify-content:center;">
                        <div class="b2sell-modal-inner" style="background:#fff;padding:20px;max-width:500px;width:90%;">
                            <h2>Sugerencia automática por B2SELL GPT Assistant</h2>
                            <pre id="b2sell-gpt-text" style="white-space:pre-wrap"></pre>
                            <p>
                                <button class="button" id="b2sell-gpt-copy">Copiar</button>
                                <button class="button button-primary" id="b2sell-gpt-insert">Insertar</button>
                                <button class="button" id="b2sell-gpt-close">Cerrar</button>
                            </p>
                        </div>
                    </div>';
                echo '<script>
                const b2sell_gpt_nonce = "' . esc_js( $nonce ) . '";
                jQuery(function($){
                    $(".b2sell-gpt-suggest").on("click",function(){
                        const action=$(this).data("action");
                        const post=$(this).data("post");
                        const keyword=$(this).data("keyword");
                        const current=$(this).data("current");
                        $("#b2sell-gpt-modal").css("display","flex");
                        $("#b2sell-gpt-text").text("Generando...");
                        $("#b2sell-gpt-insert").data("action",action).data("post",post);
                        $.post(ajaxurl,{action:"b2sell_gpt_generate",gpt_action:action,keyword:keyword,paragraph:current,post_id:post,_wpnonce:b2sell_gpt_nonce},function(res){
                            if(res.success){$("#b2sell-gpt-text").text(res.data.content);}else{$("#b2sell-gpt-text").text(res.data);}
                        });
                    });
                    $("#b2sell-gpt-copy").on("click",function(){
                        navigator.clipboard.writeText($("#b2sell-gpt-text").text());
                    });
                    $("#b2sell-gpt-insert").on("click",function(){
                        const action=$(this).data("action");
                        const post=$(this).data("post");
                        const content=$("#b2sell-gpt-text").text();
                        $.post(ajaxurl,{action:"b2sell_gpt_insert",gpt_action:action,post_id:post,content:content,_wpnonce:b2sell_gpt_nonce},function(res){
                            alert(res.success?"Contenido insertado":res.data);
                        });
                    });
                    $("#b2sell-gpt-close").on("click",function(){
                        $("#b2sell-gpt-modal").hide();
                    });
                });
                </script>';
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
                echo '<table class="widefat fixed"><thead><tr><th>Imagen</th><th>ALT actual</th><th>Sugerencia GPT</th><th>Tamaño/Dimensiones</th><th>Acción</th></tr></thead><tbody>';
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
                    echo '<td class="b2sell-gpt-suggestion"></td>';
                    $size_cell = $oversize ? '<span class="b2sell-red">' . esc_html( $size_text . ' / ' . $dim_text ) . '</span><br/><em>Optimiza esta imagen para mejorar velocidad de carga</em>' : esc_html( $size_text . ' / ' . $dim_text );
                    echo '<td>' . $size_cell . '</td>';
                    if ( '' === $im['alt'] ) {
                        $keyword = sanitize_title( basename( parse_url( $im['src'], PHP_URL_PATH ) ) );
                        echo '<td><button class="button b2sell-gpt-image" data-src="' . esc_attr( $im['src'] ) . '" data-post="' . esc_attr( $post_id ) . '" data-keyword="' . esc_attr( $keyword ) . '">Sugerir ALT</button></td>';
                    } else {
                        echo '<td>-</td>';
                    }
                    echo '</tr>';
                }
                echo '</tbody></table>';
                echo '<p><strong>Recomendaciones generales:</strong></p><ul><li>Usar nombres de archivo descriptivos.</li><li>Incluir siempre ALT.</li><li>Comprimir imágenes pesadas.</li><li>Usar formatos modernos (WebP).</li></ul>';
                echo '<script>jQuery(function($){$(".b2sell-gpt-image").on("click",function(){const btn=$(this);const src=btn.data("src");const post=btn.data("post");const keyword=btn.data("keyword");const cell=btn.closest("tr").find(".b2sell-gpt-suggestion");cell.text("Generando...");$.post(ajaxurl,{action:"b2sell_gpt_generate",gpt_action:"alt",keyword:keyword,post_id:post,_wpnonce:b2sell_gpt_nonce},function(res){if(res.success){cell.html("<pre>"+res.data.content+"</pre><button class=\"button b2sell-gpt-copy\">Copiar</button> <button class=\"button b2sell-gpt-insert\" data-post=\""+post+"\" data-src=\""+src+"\">Insertar</button>");}else{cell.text(res.data.message||res.data);}});});$(document).on("click",".b2sell-gpt-copy",function(){navigator.clipboard.writeText($(this).prev("pre").text());});$(document).on("click",".b2sell-gpt-insert",function(){const btn=$(this);const post=btn.data("post");const src=btn.data("src");const content=btn.prev("pre").text();$.post(ajaxurl,{action:"b2sell_gpt_insert",gpt_action:"alt",post_id:post,content:content,image_src:src,_wpnonce:b2sell_gpt_nonce},function(res){alert(res.success?"ALT insertado":res.data);});});});</script>';
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
        $post     = get_post( $post_id );
        $content  = $post->post_content;
        $metrics  = array();
        $recs     = array(); // array of arrays with message/action/current/keyword
        $score    = 0;
        $host     = parse_url( home_url(), PHP_URL_HOST );

        // Title length
        $title_len   = strlen( $post->post_title );
        $title_score = ( $title_len >= 30 && $title_len <= 60 ) ? 10 : ( ( $title_len >= 20 && $title_len <= 70 ) ? 5 : 0 );
        $score      += $title_score;
        if ( 10 === $title_score ) {
            $title_color = 'green';
        } elseif ( 5 === $title_score ) {
            $title_color = 'yellow';
            $recs[]      = array(
                'message' => 'El título debería estar entre 30 y 60 caracteres.',
                'action'  => 'title',
                'current' => $post->post_title,
                'keyword' => $keyword,
            );
        } else {
            $title_color = 'red';
            $recs[]      = array(
                'message' => 'El título excede los límites recomendados (30-60 caracteres).',
                'action'  => 'title',
                'current' => $post->post_title,
                'keyword' => $keyword,
            );
        }
        $metrics['Longitud del título'] = array(
            'value' => $title_len . ' caracteres',
            'color' => $title_color,
        );

        // Meta description
        $meta_description = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
        if ( empty( $meta_description ) ) {
            $meta_description = get_the_excerpt( $post_id );
        }
        $meta_len   = strlen( $meta_description );
        $meta_score = ( $meta_len >= 70 && $meta_len <= 160 ) ? 10 : ( ( $meta_len >= 50 && $meta_len <= 170 ) ? 5 : 0 );
        $score     += $meta_score;
        if ( 10 === $meta_score ) {
            $meta_color = 'green';
        } elseif ( 5 === $meta_score ) {
            $meta_color = 'yellow';
            $recs[]     = array(
                'message' => 'La meta description debería tener entre 70 y 160 caracteres.',
                'action'  => 'meta',
                'current' => $meta_description,
                'keyword' => $keyword ? $keyword : $post->post_title,
            );
        } else {
            $meta_color = 'red';
            $recs[]     = array(
                'message' => 'La meta description está fuera del rango recomendado (70-160 caracteres).',
                'action'  => 'meta',
                'current' => $meta_description,
                'keyword' => $keyword ? $keyword : $post->post_title,
            );
        }
        $metrics['Longitud de meta description'] = array(
            'value' => $meta_len . ' caracteres',
            'color' => $meta_color,
        );

        // Parse content
        $dom = new DOMDocument();
        libxml_use_internal_errors( true );
        $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $content );
        libxml_clear_errors();
        $text_content = wp_strip_all_tags( $content );

        // Headings
        $h1 = $h2 = $h3 = false;
        foreach ( array( 'h1', 'h2', 'h3' ) as $tag ) {
            $elements = $dom->getElementsByTagName( $tag );
            foreach ( $elements as $el ) {
                $text = strtolower( $el->textContent );
                if ( $keyword && false !== strpos( $text, strtolower( $keyword ) ) ) {
                    if ( 'h1' === $tag ) {
                        $h1 = true;
                    }
                    if ( 'h2' === $tag ) {
                        $h2 = true;
                    }
                    if ( 'h3' === $tag ) {
                        $h3 = true;
                    }
                }
            }
        }
        $head_score = 0;
        if ( $keyword ) {
            $head_score += $h1 ? 4 : 0;
            $head_score += $h2 ? 3 : 0;
            $head_score += $h3 ? 3 : 0;
            if ( ! $h1 ) {
                $recs[] = array( 'message' => 'Agregar la palabra clave en un H1.' );
            }
            if ( ! $h2 ) {
                $recs[] = array( 'message' => 'Agregar la palabra clave en algún H2.' );
            }
            if ( ! $h3 ) {
                $recs[] = array( 'message' => 'Agregar la palabra clave en algún H3.' );
            }
        }
        $score += $head_score;
        $head_color = ( $head_score >= 7 ) ? 'green' : ( ( $head_score >= 3 ) ? 'yellow' : 'red' );
        $metrics['Uso de keyword en H1/H2/H3'] = array(
            'value' => ( $h1 ? 'H1 ' : '' ) . ( $h2 ? 'H2 ' : '' ) . ( $h3 ? 'H3' : '' ),
            'color' => $head_color,
        );

        // Links
        $internal_links = 0;
        $external_links = 0;
        $links          = $dom->getElementsByTagName( 'a' );
        foreach ( $links as $a ) {
            $href = $a->getAttribute( 'href' );
            if ( ! $href ) {
                continue;
            }
            $href_host = parse_url( $href, PHP_URL_HOST );
            if ( ! $href_host || $href_host === $host ) {
                $internal_links++;
            } else {
                $external_links++;
            }
        }
        $link_score = ( $internal_links > 0 ? 5 : 0 ) + ( $external_links > 0 ? 5 : 0 );
        $score     += $link_score;
        if ( $internal_links === 0 ) {
            $recs[] = array( 'message' => 'Agregar enlaces internos.' );
        }
        if ( $external_links === 0 ) {
            $recs[] = array( 'message' => 'Agregar enlaces externos.' );
        }
        $links_color                     = ( 10 === $link_score ) ? 'green' : ( ( $link_score >= 5 ) ? 'yellow' : 'red' );
        $metrics['Enlaces internos/externos'] = array(
            'value' => $internal_links . ' internos / ' . $external_links . ' externos',
            'color' => $links_color,
        );

        // Images ALT
        $images          = $dom->getElementsByTagName( 'img' );
        $img_total       = $images->length;
        $img_with_alt    = 0;
        foreach ( $images as $img ) {
            if ( '' !== $img->getAttribute( 'alt' ) ) {
                $img_with_alt++;
            }
        }
        if ( 0 === $img_total ) {
            $alt_score = 10;
        } elseif ( $img_with_alt === $img_total ) {
            $alt_score = 10;
        } elseif ( $img_with_alt > 0 ) {
            $alt_score = 5;
            $recs[]    = array(
                'message' => 'Agregar atributos ALT a todas las imágenes.',
                'action'  => 'alt',
                'current' => $post->post_title,
                'keyword' => $keyword ? $keyword : $post->post_title,
            );
        } else {
            $alt_score = 0;
            $recs[]    = array(
                'message' => 'Agregar atributos ALT a las imágenes.',
                'action'  => 'alt',
                'current' => $post->post_title,
                'keyword' => $keyword ? $keyword : $post->post_title,
            );
        }
        $score     += $alt_score;
        $alt_color  = ( 10 === $alt_score ) ? 'green' : ( ( $alt_score > 0 ) ? 'yellow' : 'red' );
        $metrics['Imágenes con ALT'] = array(
            'value' => $img_with_alt . '/' . $img_total,
            'color' => $alt_color,
        );

        // Schema.org
        $schema = false;
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
        $score       += $schema_score;
        if ( ! $schema ) {
            $recs[] = array( 'message' => 'Agregar datos estructurados (schema.org).' );
        }
        $schema_color                    = $schema ? 'green' : 'red';
        $metrics['Uso de schema.org'] = array(
            'value' => $schema ? 'Sí' : 'No',
            'color' => $schema_color,
        );

        // Keyword density
        $text          = strtolower( wp_strip_all_tags( $content ) );
        $words         = str_word_count( $text, 1 );
        $total_words   = count( $words );
        $keyword_count = 0;
        if ( $keyword ) {
            foreach ( $words as $w ) {
                if ( $w === strtolower( $keyword ) ) {
                    $keyword_count++;
                }
            }
        }
        $density = $total_words ? ( $keyword_count / $total_words ) * 100 : 0;
        if ( $keyword ) {
            if ( $density >= 1 && $density <= 3 ) {
                $density_score = 10;
                $density_color = 'green';
            } elseif ( $density >= 0.5 && $density <= 4 ) {
                $density_score = 5;
                $density_color = 'yellow';
                $recs[]        = array(
                    'message' => 'Ajustar la densidad de palabras clave entre 1% y 3%.',
                    'action'  => 'rewrite',
                    'current' => wp_trim_words( $text_content, 50, '' ),
                    'keyword' => $keyword,
                );
            } else {
                $density_score = 0;
                $density_color = 'red';
                $recs[]        = array(
                    'message' => 'Densidad de palabras clave fuera de rango (1%-3%).',
                    'action'  => 'rewrite',
                    'current' => wp_trim_words( $text_content, 50, '' ),
                    'keyword' => $keyword,
                );
            }
        } else {
            $density_score = 0;
            $density_color = 'red';
            $recs[]        = array( 'message' => 'Definir una palabra clave principal.' );
        }
        $score += $density_score;
        $metrics['Densidad de palabra clave'] = array(
            'value' => round( $density, 2 ) . '%',
            'color' => $density_color,
        );

        // Readability
        $readability       = $this->flesch_reading_ease( $text_content );
        if ( $readability > 60 ) {
            $read_score = 10;
            $read_color = 'green';
        } elseif ( $readability > 40 ) {
            $read_score = 5;
            $read_color = 'yellow';
            $recs[]     = array( 'message' => 'Mejorar la legibilidad del texto.' );
        } else {
            $read_score = 0;
            $read_color = 'red';
            $recs[]     = array( 'message' => 'Legibilidad baja, simplificar el contenido.' );
        }
        $score += $read_score;
        $metrics['Legibilidad (Flesch)'] = array(
            'value' => round( $readability, 2 ),
            'color' => $read_color,
        );

        // Page load time
        $start     = microtime( true );
        $response  = wp_remote_get( get_permalink( $post_id ) );
        $load_time = round( microtime( true ) - $start, 2 );
        if ( is_wp_error( $response ) ) {
            $load_time = 0;
        }
        if ( $load_time > 0 && $load_time <= 2 ) {
            $speed_score = 10;
            $speed_color = 'green';
        } elseif ( $load_time > 0 && $load_time <= 4 ) {
            $speed_score = 5;
            $speed_color = 'yellow';
            $recs[]      = array( 'message' => 'Mejorar el tiempo de carga (<2s).' );
        } else {
            $speed_score = 0;
            $speed_color = 'red';
            $recs[]      = array( 'message' => 'Tiempo de carga excesivo (>4s).' );
        }
        $score += $speed_score;
        $metrics['Tiempo de carga'] = array(
            'value' => $load_time . 's',
            'color' => $speed_color,
        );

        $score_color = ( $score >= 80 ) ? 'green' : ( ( $score >= 50 ) ? 'yellow' : 'red' );

        return array(
            'score'            => $score,
            'score_color'      => $score_color,
            'metrics'          => $metrics,
            'recommendations'  => $recs,
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
}
