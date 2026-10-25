<?php
/**
 * Gabarit de page pour « Activer mon compte » : en-tête et pied du thème autour du formulaire.
 */

defined( 'ABSPATH' ) || exit;

get_header();
MLA_Activation::render();
get_footer();
