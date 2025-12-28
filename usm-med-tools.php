<?php
/**
 * Plugin Name: USMedication Tools
 * Description: Core medication tools (RxNorm-based) for USMedication.com
 * Version: 0.7
 * Author: USMedication
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Normalize user search term:
 * - remove strengths like "25", "25mg", etc.
 * - strip everything after first digit
 * - keep first word as base drug name
 */
function usm_normalize_search_term( $term ) {
    $term = trim( $term );

    // If there is a digit anywhere, cut string at first digit
    $len = strlen( $term );
    for ( $i = 0; $i < $len; $i++ ) {
        if ( ctype_digit( $term[$i] ) ) {
            $term = substr( $term, 0, $i );
            break;
        }
    }

    // Also cut to first word (before space), to ignore "tablet", "mg" etc.
    $parts = preg_split( '/\s+/', $term );
    if ( ! empty( $parts ) ) {
        $term = $parts[0];
    }

    return trim( $term );
}

/**
 * Helper: Get RxCUI from a drug name using RxNorm
 */
function usm_rx_get_rxcui_from_name( $name ) {
    $normalized = usm_normalize_search_term( $name );
    if ( $normalized === '' ) {
        return null;
    }

    $api_url  = 'https://rxnav.nlm.nih.gov/REST/rxcui.json?name=' . urlencode( $normalized );
    $response = wp_remote_get( $api_url, array( 'timeout' => 10 ) );

    if ( is_wp_error( $response ) ) {
        return null;
    }

    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );

    return $data['idGroup']['rxnormId'][0] ?? null;
}

/**
 * Helper: RxNorm spelling suggestions – for misspelled names
 */
function usm_rx_get_spelling_suggestions( $term ) {
    $normalized = usm_normalize_search_term( $term );
    if ( $normalized === '' ) {
        return array();
    }

    $api_url  = 'https://rxnav.nlm.nih.gov/REST/spellingsuggestions.json?name=' . urlencode( $normalized );
    $response = wp_remote_get( $api_url, array( 'timeout' => 10 ) );

    if ( is_wp_error( $response ) ) {
        return array();
    }

    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );

    if (
        isset( $data['suggestionGroup']['suggestionList']['suggestion'] )
        && is_array( $data['suggestionGroup']['suggestionList']['suggestion'] )
    ) {
        return $data['suggestionGroup']['suggestionList']['suggestion'];
    }

    return array();
}

/**
 * Helper: Get related concepts by TTY (e.g., IN, BN, SCD, SBD)
 */
function usm_rx_get_related_by_tty( $rxcui, $ttys = array() ) {
    if ( empty( $rxcui ) || empty( $ttys ) ) {
        return array();
    }

    $tty_param   = implode( '+', array_map( 'urlencode', $ttys ) );
    $related_url = 'https://rxnav.nlm.nih.gov/REST/rxcui/' . urlencode( $rxcui ) . '/related.json?tty=' . $tty_param;

    $response = wp_remote_get( $related_url, array( 'timeout' => 10 ) );
    if ( is_wp_error( $response ) ) {
        return array();
    }

    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );

    return $data['relatedGroup']['conceptGroup'] ?? array();
}

/* ============================================================
 * NEW HELPERS FOR TOOL 3 (Side-Effects & Safety)
 * ============================================================*/

/**
 * Get the primary active ingredient (IN) for a medication
 */
function usm_rx_get_primary_ingredient( $rxcui ) {
    $groups = usm_rx_get_related_by_tty( $rxcui, array( 'IN' ) );
    if ( empty( $groups ) ) return null;

    foreach ( $groups as $group ) {
        if ( isset( $group['conceptProperties'][0]['name'] ) ) {
            return $group['conceptProperties'][0]['name'];
        }
    }
    return null;
}

/**
 * Get basic side effects using RxClass Adverse Reaction Class (ARC)
 */
function usm_rx_get_adverse_reactions( $ingredient_name ) {

    if ( ! $ingredient_name ) return array();

    $url = "https://rxnav.nlm.nih.gov/REST/rxclass/class/byDrugName.json?drugName=" . urlencode( $ingredient_name ) . "&classTypes=ARC";

    $response = wp_remote_get( $url, array( 'timeout' => 10 ) );
    if ( is_wp_error( $response ) ) return array();

    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( empty( $data['rxclassDrugInfoList']['rxclassDrugInfo'] ) ) return array();

    $effects = array();
    foreach ( $data['rxclassDrugInfoList']['rxclassDrugInfo'] as $info ) {
        if ( isset( $info['rxclassMinConceptItem']['className'] ) ) {
            $effects[] = $info['rxclassMinConceptItem']['className'];
        }
    }

    return array_unique( $effects );
}

/**
 * ADVANCED safety info (interactions, warnings, contraindications)
 * Using RxNorm relationships
 */
function usm_rx_get_advanced_safety( $rxcui ) {

    $safety = array(
        'warnings'          => array(),
        'interactions'      => array(),
        'contraindications' => array(),
    );

    if ( ! $rxcui ) return $safety;

    // Map rela → which bucket to fill
    $rela_map = array(
        'interacts_with'       => 'interactions',
        'contraindicated_with' => 'contraindications',
        'has_warning'          => 'warnings',
        'has_precaution'       => 'warnings',
    );

    foreach ( $rela_map as $rela => $bucket ) {

        $url = "https://rxnav.nlm.nih.gov/REST/rxcui/$rxcui/related.json?rela=" . urlencode( $rela );
        $response = wp_remote_get( $url, array( 'timeout' => 10 ) );

        if ( is_wp_error( $response ) ) continue;

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $data['relatedGroup']['conceptGroup'] ) ) continue;

        foreach ( $data['relatedGroup']['conceptGroup'] as $g ) {
            if ( ! empty( $g['conceptProperties'] ) ) {
                foreach ( $g['conceptProperties'] as $p ) {

                    $name = $p['name'] ?? '';
                    if ( ! $name ) continue;

                    $safety[ $bucket ][] = $name;
                }
            }
        }
    }

    // Deduplicate
    foreach ( $safety as $key => $val ) {
        $safety[ $key ] = array_unique( $val );
    }

    return $safety;
}

/**
 * Master shortcode: [usm_tool type="lookup"] or [usm_tool type="altbrands"] or [usm_tool type="safety"]
 */
function usm_tool_shortcode( $atts ) {
    $atts = shortcode_atts(
        array(
            'type' => 'lookup', // default tool
        ),
        $atts,
        'usm_tool'
    );

    ob_start();

    switch ( $atts['type'] ) {
        case 'altbrands':
            usm_render_altbrands_tool();
            break;

        case 'safety':
        case 'sideeffects':
        case 'side_effects':
            usm_render_sideeffects_tool();
            break;

        case 'lookup':
        default:
            usm_render_lookup_tool();
            break;
    }

    return ob_get_clean();
}
add_shortcode( 'usm_tool', 'usm_tool_shortcode' );

/**
 * TOOL 1: Brand & Ingredient Lookup
 * [usm_tool type="lookup"]
 */
function usm_render_lookup_tool() {

    // NEW: Determine search term from POST or URL (?drug=)
    $searched_name = '';
    if ( isset( $_POST['usm_med_submit'], $_POST['usm_med_name'] ) && $_POST['usm_med_name'] !== '' ) {
        $searched_name = sanitize_text_field( $_POST['usm_med_name'] );
    } elseif ( isset( $_GET['drug'] ) && $_GET['drug'] !== '' ) {
        $searched_name = sanitize_text_field( wp_unslash( $_GET['drug'] ) );
    }

    ?>
    <div class="usm-tool usm-tool-lookup" id="usm-med-lookup">
        <div class="usm-tool-card">

            <header class="usm-tool-header">
                <div class="usm-tool-badge">
                    <span class="usm-tool-badge-icon">🛡️</span>
                    <span class="usm-tool-badge-text">U.S. Medication</span>
                </div>
                <h2 class="usm-tool-title">Medication Information Lookup</h2>
                <p class="usm-tool-subtitle">
                    Enter a medicine name to view its active formula, common U.S. brand names,
                    and example formulations.
                </p>
            </header>

            <form method="post" class="usm-tool-form">
                <label for="usm_med_name" class="usm-tool-label">
                    Enter a medicine name
                </label>

                <div class="usm-tool-input-row">
                    <input
                        type="text"
                        id="usm_med_name"
                        name="usm_med_name"
                        value="<?php echo esc_attr( $searched_name ); ?>"
                        required
                        class="usm-tool-input"
                        placeholder="e.g., Tylenol, Atorvastatin, Metformin"
                    />
                    <button type="submit" name="usm_med_submit" class="usm-tool-button">
                        <span class="usm-tool-button-icon">🔎</span>
                        <span>Look up</span>
                    </button>
                </div>

                <p class="usm-tool-helper">
                    Tip: Start with the brand name printed on your prescription label or package.
                </p>
            </form>

            <!-- Loading indicator (shown via JS when form submits) -->
            <div class="usm-tool-loading" aria-hidden="true">
                <span class="usm-spinner"></span>
                <span class="usm-loading-text">Looking up medication details…</span>
            </div>

            <?php
            // NEW: Run lookup if we have a search term from POST or URL
            if ( ! empty( $searched_name ) ) {

                $rxcui = usm_rx_get_rxcui_from_name( $searched_name );

                echo '<section class="usm-tool-results">';

                /**
                 * NO DIRECT MATCH → Try spelling suggestions
                 */
                if ( ! $rxcui ) {

                    $suggestions = usm_rx_get_spelling_suggestions( $searched_name );

                    echo '<div class="usm-tool-message usm-tool-message--error">';
                    echo '<h3>No exact match found</h3>';
                    echo '<p>We could not find a U.S. medication matching <strong>' . esc_html( $searched_name ) . '</strong>.</p>';

                    if ( ! empty( $suggestions ) ) {
                        echo '<p>Did you mean:</p>';
                        echo '<ul class="usm-tool-suggestions">';
                        foreach ( $suggestions as $s ) {
                            echo '<li>';
                            echo '<button type="button" class="usm-suggestion" data-name="' . esc_attr( $s ) . '">';
                            echo esc_html( $s );
                            echo '</button>';
                            echo '</li>';
                        }
                        echo '</ul>';
                    } else {
                        echo '<p>Please check the spelling or try another name. If you know the generic name, try that instead.</p>';
                    }

                    echo '</div>';
                    echo '</section></div></div>'; // .usm-tool-results, .usm-tool-card, .usm-tool
                    return;
                }

                // SUMMARY STRIP
                echo '<div class="usm-tool-summary">';
                echo '  <div class="usm-tool-summary-item">';
                echo '      <span class="usm-tool-summary-label">Search term</span>';
                echo '      <span class="usm-tool-summary-value">' . esc_html( $searched_name ) . '</span>';
                echo '  </div>';

                echo '  <div class="usm-tool-summary-item">';
                echo '      <span class="usm-tool-summary-label">RxNorm ID</span>';
                echo '      <span class="usm-tool-summary-value">' . esc_html( $rxcui ) . '</span>';
                echo '  </div>';
                echo '</div>';

                // FETCH RELATED DATA
                $groups = usm_rx_get_related_by_tty( $rxcui, array( 'IN', 'BN', 'SCD', 'SBD' ) );

                $ingredients  = array();
                $brands       = array();
                $formulations = array();

                foreach ( $groups as $group ) {
                    $tty   = $group['tty'] ?? '';
                    $props = $group['conceptProperties'] ?? array();

                    foreach ( $props as $prop ) {
                        $name = $prop['name'] ?? '';
                        if ( ! $name ) {
                            continue;
                        }

                        if ( $tty === 'IN' ) {
                            $ingredients[] = $name;
                        } elseif ( $tty === 'BN' ) {
                            $brands[] = $name;
                        } elseif ( in_array( $tty, array( 'SCD', 'SBD' ), true ) ) {
                            $formulations[] = $name;
                        }
                    }
                }

                $ingredients  = array_unique( $ingredients );
                $brands       = array_slice( array_unique( $brands ), 0, 25 );
                $formulations = array_slice( array_unique( $formulations ), 0, 12 );

                // GRID LAYOUT
                echo '<div class="usm-tool-grid">';

                // LEFT COLUMN
                echo '<div class="usm-tool-column">';

                if ( ! empty( $ingredients ) ) {
                    echo '<article class="usm-tool-section">';
                    echo '  <h3 class="usm-tool-section-title">Medication formula (active ingredient)</h3>';
                    echo '  <div class="usm-tool-section-body usm-tool-pills">';
                    foreach ( $ingredients as $ing ) {
                        echo '      <span class="usm-pill usm-pill--formula">' . esc_html( $ing ) . '</span>';
                    }
                    echo '  </div>';
                    echo '</article>';
                }

                if ( ! empty( $brands ) ) {
                    echo '<article class="usm-tool-section">';
                    echo '  <h3 class="usm-tool-section-title">Common U.S. brand name(s)</h3>';
                    echo '  <div class="usm-tool-section-body usm-tool-pills">';
                    $total_brands = count( $brands );
                    foreach ( $brands as $index => $brand ) {
                        $label = $brand . ( $index < $total_brands - 1 ? ', ' : '' );
                        echo '      <span class="usm-pill usm-pill--brand">' . esc_html( $label ) . '</span>';
                    }
                    echo '  </div>';
                    echo '</article>';
                }

                echo '</div>'; // LEFT COLUMN

                // RIGHT COLUMN – FORMULATIONS
                echo '<div class="usm-tool-column">';
                if ( ! empty( $formulations ) ) {
                    echo '<article class="usm-tool-section">';
                    echo '  <h3 class="usm-tool-section-title">Medication formulations</h3>';
                    echo '  <ul class="usm-tool-list">';
                    foreach ( $formulations as $f ) {
                        echo '      <li>' . esc_html( $f ) . '</li>';
                    }
                    echo '  </ul>';
                    echo '</article>';
                }
                echo '</div>'; // RIGHT COLUMN

                echo '</div>'; // GRID

                echo '<p class="usm-tool-disclaimer">';
                echo 'This tool uses U.S. RxNorm data and is provided for informational purposes only. ';
                echo 'It does not provide medical advice, diagnosis, or treatment and does not replace ';
                echo 'guidance from a licensed healthcare professional.';
                echo '</p>';

                // NEW: Update URL to include ?drug=<searched_name>
                echo '<script>(function(){try{';
                echo 'var q=' . wp_json_encode( $searched_name ) . ';';
                echo 'if(q){var url=new URL(window.location.href);url.searchParams.set("drug",q);window.history.replaceState(null,"",url.toString());}';
                echo '}catch(e){}})();</script>';

                echo '</section>'; // .usm-tool-results
            }
            ?>

        </div><!-- /.usm-tool-card -->
    </div><!-- /.usm-tool -->
    <?php
}

/**
 * TOOL 2: Alternative Brand Finder
 * [usm_tool type="altbrands"]
 */
function usm_render_altbrands_tool() {

    // NEW: Determine search term from POST or URL (?drug=) for this tool
    $searched_name = '';
    if ( isset( $_POST['usm_alt_med_submit'], $_POST['usm_alt_med_name'] ) && $_POST['usm_alt_med_name'] !== '' ) {
        $searched_name = sanitize_text_field( $_POST['usm_alt_med_name'] );
    } elseif ( isset( $_GET['drug'] ) && $_GET['drug'] !== '' ) {
        $searched_name = sanitize_text_field( wp_unslash( $_GET['drug'] ) );
    }

    ?>
    <div class="usm-tool usm-tool-altbrands" id="usm-altbrands-tool">
        <div class="usm-tool-card">

            <header class="usm-tool-header">
                <div class="usm-tool-badge">
                    <span class="usm-tool-badge-icon">💊</span>
                    <span class="usm-tool-badge-text">U.S. Medication</span>
                </div>
                <h2 class="usm-tool-title">Alternative Medication Brand Finder</h2>
                <p class="usm-tool-subtitle">
                    Enter a medicine name to see its core active ingredient and other U.S. brand names
                    that may use the same medication formula.
                </p>
            </header>

            <form method="post" class="usm-tool-form">
                <label for="usm_alt_med_name" class="usm-tool-label">
                    Enter a brand or generic name
                </label>

                <div class="usm-tool-input-row">
                    <input
                        type="text"
                        id="usm_alt_med_name"
                        name="usm_alt_med_name"
                        value="<?php echo esc_attr( $searched_name ); ?>"
                        required
                        class="usm-tool-input"
                        placeholder="e.g., Viagra, Lipitor, Zoloft"
                    />
                    <button type="submit" name="usm_alt_med_submit" class="usm-tool-button">
                        <span class="usm-tool-button-icon">🔁</span>
                        <span>Find alternatives</span>
                    </button>
                </div>

                <p class="usm-tool-helper">
                    Use this tool to understand which other U.S. brands may share the same active ingredient.
                </p>
            </form>

            <!-- Loading indicator (shared CSS/JS behaviour) -->
            <div class="usm-tool-loading" aria-hidden="true">
                <span class="usm-spinner"></span>
                <span class="usm-loading-text">Finding alternative brands…</span>
            </div>

            <?php
            // NEW: Run logic if we have a search term (from POST or URL)
            if ( ! empty( $searched_name ) ) {

                $rxcui = usm_rx_get_rxcui_from_name( $searched_name );

                echo '<section class="usm-tool-results">';

                if ( ! $rxcui ) {
                    $suggestions = usm_rx_get_spelling_suggestions( $searched_name );

                    echo '<div class="usm-tool-message usm-tool-message--error">';
                    echo '<h3>No exact match found</h3>';
                    echo '<p>We could not find a U.S. medication matching <strong>' . esc_html( $searched_name ) . '</strong>.</p>';

                    if ( ! empty( $suggestions ) ) {
                        echo '<p>Did you mean:</p>';
                        echo '<ul class="usm-tool-suggestions">';
                        foreach ( $suggestions as $s ) {
                            echo '<li>';
                            echo '<button type="button" class="usm-suggestion" data-name="' . esc_attr( $s ) . '">';
                            echo esc_html( $s );
                            echo '</button>';
                            echo '</li>';
                        }
                        echo '</ul>';
                    } else {
                        echo '<p>Please check the spelling or try another name. If you know the generic name, try that instead.</p>';
                    }

                    echo '</div>';
                    echo '</section></div></div>';
                    return;
                }

                // SUMMARY STRIP
                echo '<div class="usm-tool-summary">';
                echo '  <div class="usm-tool-summary-item">';
                echo '      <span class="usm-tool-summary-label">Search term</span>';
                echo '      <span class="usm-tool-summary-value">' . esc_html( $searched_name ) . '</span>';
                echo '  </div>';

                echo '  <div class="usm-tool-summary-item">';
                echo '      <span class="usm-tool-summary-label">RxNorm ID</span>';
                echo '      <span class="usm-tool-summary-value">' . esc_html( $rxcui ) . '</span>';
                echo '  </div>';
                echo '</div>';

                /**
                 * STEP 1: Get the INGREDIENT(s) (IN) for this RxCUI
                 */
                $ingredient_groups = usm_rx_get_related_by_tty( $rxcui, array( 'IN' ) );

                $ingredients       = array(); // names
                $ingredient_rxcuis = array(); // rxcui values

                foreach ( $ingredient_groups as $group ) {
                    $tty   = $group['tty'] ?? '';
                    $props = $group['conceptProperties'] ?? array();

                    if ( $tty !== 'IN' ) {
                        continue;
                    }

                    foreach ( $props as $prop ) {
                        $name    = $prop['name'] ?? '';
                        $ing_rxc = $prop['rxcui'] ?? '';

                        if ( ! $name || ! $ing_rxc ) {
                            continue;
                        }

                        $ingredients[]       = $name;
                        $ingredient_rxcuis[] = $ing_rxc;
                    }
                }

                $ingredients       = array_unique( $ingredients );
                $ingredient_rxcuis = array_unique( $ingredient_rxcuis );

                /**
                 * STEP 2: For each ingredient RxCUI, get ALL brand names (BN)
                 */
                $all_brands = array();

                foreach ( $ingredient_rxcuis as $ing_rxcui ) {
                    $brand_groups = usm_rx_get_related_by_tty( $ing_rxcui, array( 'BN' ) );

                    foreach ( $brand_groups as $bgroup ) {
                        $btty   = $bgroup['tty'] ?? '';
                        $bprops = $bgroup['conceptProperties'] ?? array();

                        if ( $btty !== 'BN' ) {
                            continue;
                        }

                        foreach ( $bprops as $bprop ) {
                            $bname = $bprop['name'] ?? '';
                            if ( ! $bname ) {
                                continue;
                            }
                            $all_brands[] = $bname;
                        }
                    }
                }

                /**
                 * STEP 3: Remove the original brand from the alternatives list
                 */
                $brands = array();
                $search_norm = strtolower( usm_normalize_search_term( $searched_name ) );

                foreach ( $all_brands as $bname ) {
                    $norm = strtolower( usm_normalize_search_term( $bname ) );
                    if ( $norm === $search_norm ) {
                        continue; // skip same brand
                    }
                    $brands[] = $bname;
                }

                $brands = array_slice( array_unique( $brands ), 0, 60 );

                // GRID LAYOUT
                echo '<div class="usm-tool-grid">';

                // LEFT COLUMN – ingredient
                echo '<div class="usm-tool-column">';
                if ( ! empty( $ingredients ) ) {
                    echo '<article class="usm-tool-section">';
                    echo '  <h3 class="usm-tool-section-title">Core active ingredient</h3>';
                    echo '  <div class="usm-tool-section-body usm-tool-pills">';
                    foreach ( $ingredients as $ing ) {
                        echo '      <span class="usm-pill usm-pill--formula">' . esc_html( $ing ) . '</span>';
                    }
                    echo '  </div>';
                    echo '  <p class="usm-tool-note">';
                    echo 'This is the main medication formula shared by the alternative brands listed.';
                    echo ' Always speak with your doctor or pharmacist before switching brands.';
                    echo '  </p>';
                    echo '</article>';
                }
                echo '</div>'; // LEFT COLUMN

                // RIGHT COLUMN – brands
                echo '<div class="usm-tool-column">';
                echo '<article class="usm-tool-section">';
                echo '  <h3 class="usm-tool-section-title">Alternative U.S. brand name(s)</h3>';

                if ( ! empty( $brands ) ) {
                    echo '  <div class="usm-tool-section-body usm-tool-pills">';
                    $total_brands = count( $brands );
                    foreach ( $brands as $index => $brand ) {
                        $label = $brand . ( $index < $total_brands - 1 ? ', ' : '' );
                        echo '      <span class="usm-pill usm-pill--brand">' . esc_html( $label ) . '</span>';
                    }
                    echo '  </div>';
                } else {
                    echo '  <p class="usm-tool-empty">';
                    echo 'We did not find other U.S. brands that share this exact active ingredient.';
                    echo ' This brand may be unique in the RxNorm data set.';
                    echo '  </p>';
                }

                echo '  <p class="usm-tool-note">';
                echo 'These brands may share the same active ingredient, but they are not automatically interchangeable.';
                echo ' Dosage, release form, and your personal medical situation still matter.';
                echo ' Always check with a licensed healthcare professional before changing from one product to another.';
                echo '  </p>';
                echo '</article>';
                echo '</div>'; // RIGHT COLUMN

                echo '</div>'; // GRID

                echo '<p class="usm-tool-disclaimer">';
                echo 'Do not switch or substitute medications on your own based only on brand names.';
                echo ' Always consult a licensed healthcare professional before changing from one brand or generic medicine to another,';
                echo ' even if the ingredients appear similar.';
                echo '</p>';

                // NEW: Update URL to include ?drug=<searched_name>
                echo '<script>(function(){try{';
                echo 'var q=' . wp_json_encode( $searched_name ) . ';';
                echo 'if(q){var url=new URL(window.location.href);url.searchParams.set("drug",q);window.history.replaceState(null,"",url.toString());}';
                echo '}catch(e){}})();</script>';

                echo '</section>';
            }
            ?>

        </div><!-- /.usm-tool-card -->
    </div><!-- /.usm-tool -->
    <?php
}

/* ============================================================
 * TOOL 3: Medication Side-Effects & Safety Checker
 * [usm_tool type="safety"] / [usm_tool type="sideeffects"]
 * ============================================================*/
function usm_render_sideeffects_tool() {

    // NEW: Determine search term from POST or URL (?drug=)
    $searched = '';
    if ( isset( $_POST['usm_se_submit'], $_POST['usm_se_name'] ) && $_POST['usm_se_name'] !== '' ) {
        $searched = sanitize_text_field( $_POST['usm_se_name'] );
    } elseif ( isset( $_GET['drug'] ) && $_GET['drug'] !== '' ) {
        $searched = sanitize_text_field( wp_unslash( $_GET['drug'] ) );
    }

    ?>
    <div class="usm-tool usm-sideeffects" id="usm-safety-checker">
        <div class="usm-tool-card">

            <header class="usm-tool-header">
                <div class="usm-tool-badge">
                    <span class="usm-tool-badge-icon">⚖️</span>
                    <span class="usm-tool-badge-text">U.S. Medication</span>
                </div>
                <h2 class="usm-tool-title">Medication Side-Effects & Safety Checker</h2>
                <p class="usm-tool-subtitle">
                    Enter a medicine name to view reported side effects, key warnings, interactions,
                    and contraindications derived from RxNorm/RxClass safety relationships.
                </p>
            </header>

            <form method="post" class="usm-tool-form">
                <label class="usm-tool-label">
                    Enter a medicine name
                </label>

                <div class="usm-tool-input-row">
                    <input
                        type="text"
                        name="usm_se_name"
                        value="<?php echo esc_attr( $searched ); ?>"
                        required
                        class="usm-tool-input"
                        placeholder="e.g., Metformin, Ibuprofen, Sertraline"
                    />
                    <button type="submit" name="usm_se_submit" class="usm-tool-button">
                        <span class="usm-tool-button-icon">🩺</span>
                        <span>Check safety</span>
                    </button>
                </div>
            </form>

            <?php
            // NEW: Run logic if we have a search term from POST or URL
            if ( ! empty( $searched ) ) {

                $rxcui = usm_rx_get_rxcui_from_name( $searched );

                if ( ! $rxcui ) {

                    $suggestions = usm_rx_get_spelling_suggestions( $searched );

                    echo '<div class="usm-tool-message usm-tool-message--error">';
                    echo '<h3>No exact match found</h3>';
                    echo '<p>We could not find a U.S. medication matching <strong>' . esc_html( $searched ) . '</strong>.</p>';

                    if ( ! empty( $suggestions ) ) {
                        echo '<p>Did you mean:</p>';
                        echo '<ul class="usm-tool-suggestions">';
                        foreach ( $suggestions as $s ) {
                            echo '<li>';
                            echo '<button type="button" class="usm-suggestion" data-name="' . esc_attr( $s ) . '">';
                            echo esc_html( $s );
                            echo '</button>';
                            echo '</li>';
                        }
                        echo '</ul>';
                    } else {
                        echo '<p>Please check the spelling or try another name.</p>';
                    }

                    echo '</div>';
                    echo '</div></div>';
                    return;
                }

                $ingredient       = usm_rx_get_primary_ingredient( $rxcui );
                $fallback_effects = usm_rx_get_adverse_reactions( $ingredient );
                $advanced         = usm_rx_get_advanced_safety( $rxcui );

                // SUMMARY STRIP
                echo '<div class="usm-tool-summary">';
                echo '  <div class="usm-tool-summary-item"><span class="usm-tool-summary-label">Search term</span><span class="usm-tool-summary-value">' . esc_html( $searched ) . '</span></div>';
                echo '  <div class="usm-tool-summary-item"><span class="usm-tool-summary-label">RxNorm ID</span><span class="usm-tool-summary-value">' . esc_html( $rxcui ) . '</span></div>';
                if ( $ingredient ) {
                    echo '  <div class="usm-tool-summary-item"><span class="usm-tool-summary-label">Active ingredient</span><span class="usm-tool-summary-value">' . esc_html( $ingredient ) . '</span></div>';
                }
                echo '</div>';

                echo '<div class="usm-tool-grid">';

                // LEFT COLUMN — Warnings + Interactions
                echo '<div class="usm-tool-column">';

                echo '<article class="usm-tool-section">';
                echo '  <h3 class="usm-tool-section-title">Critical warnings</h3>';
                if ( ! empty( $advanced['warnings'] ) ) {
                    echo '  <ul class="usm-tool-list">';
                    foreach ( $advanced['warnings'] as $w ) {
                        echo '      <li>' . esc_html( $w ) . '</li>';
                    }
                    echo '  </ul>';
                } else {
                    echo '  <p>No specific warnings found in RxNorm relationships.</p>';
                }
                echo '</article>';

                echo '<article class="usm-tool-section">';
                echo '  <h3 class="usm-tool-section-title">Medication interactions</h3>';
                if ( ! empty( $advanced['interactions'] ) ) {
                    echo '  <ul class="usm-tool-list">';
                    foreach ( $advanced['interactions'] as $it ) {
                        echo '      <li>' . esc_html( $it ) . '</li>';
                    }
                    echo '  </ul>';
                } else {
                    echo '  <p>No major interacting drug concepts recorded in RxNorm relations for this medication.</p>';
                }
                echo '</article>';

                echo '</div>'; // LEFT COLUMN

                // RIGHT COLUMN — Contraindications + Side effects
                echo '<div class="usm-tool-column">';

                echo '<article class="usm-tool-section">';
                echo '  <h3 class="usm-tool-section-title">Contraindications</h3>';
                if ( ! empty( $advanced['contraindications'] ) ) {
                    echo '  <ul class="usm-tool-list">';
                    foreach ( $advanced['contraindications'] as $c ) {
                        echo '      <li>' . esc_html( $c ) . '</li>';
                    }
                    echo '  </ul>';
                } else {
                    echo '  <p>No specific contraindicated concepts mapped for this medication in RxNorm.</p>';
                }
                echo '</article>';

                echo '<article class="usm-tool-section">';
                echo '  <h3 class="usm-tool-section-title">Common side-effect classes</h3>';
                if ( ! empty( $fallback_effects ) ) {
                    echo '  <ul class="usm-tool-list">';
                    foreach ( $fallback_effects as $fx ) {
                        echo '      <li>' . esc_html( $fx ) . '</li>';
                    }
                    echo '  </ul>';
                } else {
                    echo '  <p>No adverse reaction classes were found in RxClass for this ingredient.</p>';
                }
                echo '</article>';

                echo '</div>'; // RIGHT COLUMN

                echo '</div>'; // GRID

                echo '<p class="usm-tool-disclaimer">';
                echo 'This side-effects and safety checker uses U.S. RxNorm and RxClass data linked to FDA medication information.';
                echo ' It is for educational purposes only and does not replace professional medical advice, diagnosis, or treatment.';
                echo ' Always consult a licensed healthcare provider before making decisions about your medications.';
                echo '</p>';

                // NEW: Update URL to include ?drug=<searched>
                echo '<script>(function(){try{';
                echo 'var q=' . wp_json_encode( $searched ) . ';';
                echo 'if(q){var url=new URL(window.location.href);url.searchParams.set("drug",q);window.history.replaceState(null,"",url.toString());}';
                echo '}catch(e){}})();</script>';
            }
            ?>

        </div><!-- /.usm-tool-card -->
    </div><!-- /.usm-tool -->
    <?php
}
