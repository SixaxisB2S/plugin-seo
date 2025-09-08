<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class B2Sell_SEO_Analysis {

    public function render_admin_page() {
        if ( isset( $_GET['post_id'] ) ) {
            $this->render_analysis( intval( $_GET['post_id'] ) );
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
        echo '<p style="font-size:12px;color:#666;">Desarrollado por B2Sell SPA.</p>';
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
                'date'           => current_time( 'mysql' ),
                'score'          => $results['score'],
                'recommendations'=> array_slice( $results['recommendations'], 0, 3 ),
            );
            update_post_meta( $post_id, '_b2sell_seo_history', $history );
        }

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
                    echo '<li>' . esc_html( $rec ) . '</li>';
                }
                echo '</ul>';
            }
        }

        echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=b2sell-seo-analisis' ) ) . '">Volver al listado</a></p>';
        echo '</div>';
    }

    private function perform_analysis( $post_id, $keyword ) {
        $post     = get_post( $post_id );
        $content  = $post->post_content;
        $metrics  = array();
        $recs     = array();
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
            $recs[]      = 'El título debería estar entre 30 y 60 caracteres.';
        } else {
            $title_color = 'red';
            $recs[]      = 'El título excede los límites recomendados (30-60 caracteres).';
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
            $recs[]     = 'La meta description debería tener entre 70 y 160 caracteres.';
        } else {
            $meta_color = 'red';
            $recs[]     = 'La meta description está fuera del rango recomendado (70-160 caracteres).';
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
                $recs[] = 'Agregar la palabra clave en un H1.';
            }
            if ( ! $h2 ) {
                $recs[] = 'Agregar la palabra clave en algún H2.';
            }
            if ( ! $h3 ) {
                $recs[] = 'Agregar la palabra clave en algún H3.';
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
            $recs[] = 'Agregar enlaces internos.';
        }
        if ( $external_links === 0 ) {
            $recs[] = 'Agregar enlaces externos.';
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
            $recs[]    = 'Agregar atributos ALT a todas las imágenes.';
        } else {
            $alt_score = 0;
            $recs[]    = 'Agregar atributos ALT a las imágenes.';
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
            $recs[] = 'Agregar datos estructurados (schema.org).';
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
                $recs[]        = 'Ajustar la densidad de palabras clave entre 1% y 3%.';
            } else {
                $density_score = 0;
                $density_color = 'red';
                $recs[]        = 'Densidad de palabras clave fuera de rango (1%-3%).';
            }
        } else {
            $density_score = 0;
            $density_color = 'red';
            $recs[]        = 'Definir una palabra clave principal.';
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
            $recs[]     = 'Mejorar la legibilidad del texto.';
        } else {
            $read_score = 0;
            $read_color = 'red';
            $recs[]     = 'Legibilidad baja, simplificar el contenido.';
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
            $recs[]      = 'Mejorar el tiempo de carga (<2s).';
        } else {
            $speed_score = 0;
            $speed_color = 'red';
            $recs[]      = 'Tiempo de carga excesivo (>4s).';
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
