<?php


    ## old PHP memory leaked a lot.  die after 6 hours and let systemd restart
    $delay = 6;
    $end_ts = time() + ($delay*3600);

    $path = realpath(dirname(__FILE__));
    $refresh_root_hint_days = 7;


    while (time() < $end_ts) {
        if (file_exists("{$path}/../data/ubound_force_reload.lck")) {
            print ("DNS Refresh required\n");
            @unlink("{$path}/../data/ubound_force_reload.lck");

            exec("cp {$path}/../data/unbound.conf.d/* /etc/unbound/unbound.conf.d/");

            # Keep root.hints reasonbly up to date without being obnoxious
            if (!file_exists("/etc/unbound/root.hints") || ((time() - filemtime("/etc/unbound/root.hints")) > ($refresh_root_hint_days * 86400))){
                system("curl -o /etc/unbound/root.hints https://www.internic.net/domain/named.cache");
            }

            system("systemctl restart unbound");
        }
        sleep(5);
    }

