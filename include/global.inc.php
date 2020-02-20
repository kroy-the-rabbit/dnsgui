<?php

    spl_autoload_register(function($class) {
		$lookup = str_replace("\\","/",$class).'.class.php';
        if (file_exists("../classes/{$lookup}")) {
            require_once("../classes/{$lookup}");
        } else {
            die ("../include/{$lookup} not found!");
        }   
	});
