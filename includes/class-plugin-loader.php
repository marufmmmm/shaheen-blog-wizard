<?php
/**
 * Plugin loader
 */

class Shaheen_Blog_Wizard_Plugin_Loader {

    private $classes = array();

    public function __construct() {
        $this->load_classes();
    }

    private function load_classes() {
        // Classes are loaded directly in the main plugin file
        // This file is kept for potential future expansion
    }
}
