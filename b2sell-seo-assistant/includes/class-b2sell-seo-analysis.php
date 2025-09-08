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
        $posts = get_posts( array(
            'post_type'   => array( 'post', 'page' ),
            'post_status' => 'publish',
            'numberposts' => -1,
        ) );

        echo '<div class="wrap">';
        echo '<h1>Análisis SEO</h1>';
        echo '<table class="widefat fixed">';
        echo '<thead><tr><th>Título</th><th>Tipo</th><th>Último análisis</th><th>Puntaje</th><th></th></tr></thead><tbody>';
        foreach ( $posts as $p ) {
            $history = get_post_meta( $p->ID, '_b2sell_seo_history', true );
            $last    = is_array( $history ) ? end( $history ) : false;
            $date    = $last ? esc_html( $last['date'] ) : '-';
            $score   = $last ? intval( $last['score'] ) : '-';
            $link    = admin_url( 'admin.php?page=b2sell-seo-analisis&post_id=' . $p->ID );
            echo '<tr>';
            echo '<td>' . esc_html( $p->post_title ) . '</td>';
            echo '<td>' . esc_html( $p->post_type ) . '</td>';
            echo '<td>' . $date . '</td>';
            echo '<td>' . $score . '</td>';
            echo '<td><a class="button" href="' . esc_url( $link ) . '">Analizar</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
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
