# dnsgui

An Unbound GUI for local DNS resolution and forwarding/recursion.

![example](https://user-images.githubusercontent.com/20545075/74970589-3ed4be00-53e4-11ea-9893-fb761d6b4c1b.png)


This is a project that started three or four years ago when I wasn't really satisified with any of the options for GUI DNS management.  

Some of the things it supports:

* DNS Forwarding or Recursion through Unbound.  
* DNS-over-TLS.
* IPv4 and IPv6.
* Ability to easily enter one or many local domains for internal DNS resolution.
* Automatically build reverse DNS entries.
* Reasonably lightweight.
* Easily extensible through normal Unbound configuration directives.  
* Backing up is as simple as saving the .sqlite3 database and custom_host.conf somewhere.

### Basic Requirements

This has really only been deployed on Debian Buster with lighttpd (and the instructions that follow assume this).  But it would be trivial to throw Apache, nginx, CentOS, etc in front of this.

* Unbound
* PHP, specificially the 7.3.14 that is currently shipping with Buster. But it should work with earlier (to a point) and later versions.
* SQLite3
* lighttpd (if you are following this guide)


The base requirements can be installed with:

    sudo apt install php-cli php-cgi unbound sqlite3 php-sqlite3

This guide, and the included systemd service, assumes that the repository has been cloned to:

    /var/www/

This location can be changed, but you would need to update the `dns_runner.service` file.

#### lighttpd installation

I ran this for years with lighttpd, just because it was simple and lightweight.  But nginx, Apache, Caddy, or any other web server that supports CGI or PHP would work.

To install lighttpd and enable required plugins:

    sudo apt install lighttpd
    sudo lighty-enable-mod fastcgi fastcgi-php rewrite

A few small configuration changes are necessary in `/etc/lighttpd/lighttpd.conf`:

Change document root:

    server.document-root        = "/var/www/dnsgui/docroot"

Add a rewrite rule to end of the conf file, or in a new file in `/etc/lighttpd/conf-enabled`:

    url.rewrite-if-not-file = (
        "/(.*)$" => "/index.php?_url=/(",
    )

If SSL is desired, add the certificate before restarting lighttpd:

    /etc/lighttpd/server.pem #full certificate chain + private key

Enable the SSL module:

    sudo lighty-enable-mod ssl

If password protection is required, use lighttpd's built in functionality:

https://www.cyberciti.biz/tips/lighttpd-setup-a-password-protected-directory-directories.html

Restart lighttpd:

    sudo systemctl restart lighttpd.service

### Changing permissions

It is necessary to change the permissions on the data directory so that the GUI can read and write data. The user and group must match what the web server is running as.

This example assumes using lighttpd, where the default user/group is www-data, and that the GUI has been installed to the default directory.

    sudo chown -R www-data.www-data /var/www/dnsgui/data

### Service

The GUI requires a small service running to handle Unbound integration.

Assuming the default directory:

    sudo cp /var/www/dnsgui/daemon/dns_runner.service /etc/systemd/system/
    sudo systemctl daemon-reload
    sudo systemctl enable dns_runner.service
    sudo systemctl start dns_runner.service


The GUI should then be available a http://IP/, or https://IP/.

### API

For some automation, there is a *VERY* simple API for creating new zone records.  Never got around to creating a proper removal process

    /usr/bin/wget -qO- --no-check-certificate --post-data "zone=$DOMAIN&new_local_zone_mapping=$HOSTNAME&new_local_zone_mapping_ip=$IPV4_or_6&api_added=1&force_update=1" https://server_IP/dns &> /dev/null

### Back up

For backing up, all important data is in `/var/www/dnsgui/data`:

* `unbound.sqlite3` this is the primary database.
* `unbound.conf.d/custom.conf` this is the custom Unbound configuration options that have been maintained from the GUI
* `unbound.conf.d/custom_hosts.conf` this is a generated file that doesn't need to be backed up.  It is overwritten when configuration is applied.  

### Misc

Some extra stuff that didn't quite fit anywhere else.  

* `Clone zone` copies the zone that its attached to.  So `host.domain.com` will also resolve to `host.domain2.com` if `domain2.com` is a clone zone for `domain.com`.
* `Transparent/Static` see the difference in the [Unbound documentation](https://nlnetlabs.nl/documentation/unbound/unbound.conf/)

Currently there is no way to delete a zone, just hostnames.  Manually loading up the `unbound.sqlite3` database in the sqlite3 cli and deleting the record is the only way to remove a full zone.

### Security Considerations

This app is designed to be protected as management. An additional layer of security should be applied by implementing SSL and HTTP Basic Auth. With a more robust web server like nginx, it would be possible to implement two-factor authentication.  

Finally, this app includes CDN resources for bootstrap and JQuery.  It might be desirable for the hyper-security-conscious to host those locally and edit `index.html`.
