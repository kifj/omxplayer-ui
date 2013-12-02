omxplayer-ui
============

omxplayer-ui is a mobile webclient and a PHP service to control omxplayer on Rasperry Pi

Prerequisites (this is my setup, many ways lead to Rome)
 * install Raspian (http://www.raspbian.org/RaspbianInstaller)
 * you need these packages:
 * omxplayer
 * php-cgi 
 * apache2-mpm-prefork (any webserver which can run PHP scripts will do, I'm used to apache2)
 * curl
 * djmount (optional)

Setup for Nginx: 
 * you need these packages
 * nginx
 * php5-fpm
 * add this location to the site config

```
    # nginx configuration
    location /omxplayer-ui/ {
      if (!-e $request_filename) {
        rewrite ^/omxplayer-ui/(.*)$ /omxplayer-ui/index.php;
      }
    }
```

 * edit /etc/nginx/sites-available/default

```
    location ~ \.php$ {
    # fastcgi_split_path_info ^(.+\.php)(/.+)$;
    # # NOTE: You should have "cgi.fix_pathinfo = 0;" in php.ini
    #
    # # With php5-cgi alone:
    # fastcgi_pass 127.0.0.1:9000;
    # # With php5-fpm:
    fastcgi_pass unix:/var/run/php5-fpm.sock;
    fastcgi_index index.php;
    include fastcgi_params;
    }
```

 * in /etc/nginx/sites-available/default change root directory according to your need

```
    root /usr/share/nginx/www;
```

Setup (this is my setup, many ways ... you know that already)
 * djmount mounts the media servers to /media/upnp (in /etc/rc.local add /usr/bin/djmount -o allow_other,iocharset=UTF-8 /media/upnp > /dev/null)
 * if you have a different mount point or need different options for omxplayer, change the settings in the page or edit conf/settings.json
 * the first level of directories represents the servers, you can set up symlinks to samba or nfs mounts if you like
 * omxplayer-ui needs to be published at the webserver at /omxplayer-ui, or you edit the URL in mediaplayer.js
 * ensure that the directories "data" and "conf" are writable by the webserver (chmod -R 664 data conf ; chgrp -R www-data data conf)
 * ensure all directories (omxplayer-ui and your media files) are accessible for the user which runs the web server
 * make sure that PHP and mod_rewrite are enabled for the diretory which contains omxplayer-ui. AllowOverride All on the document root will do this. 
 * the UI should work well on any modern (HTML5 ready) browser, I've tested with Chrome 22, Firefox 16 and Webkit on Android 2.2

Ensure that the user which runs omxplayer has sufficient access rights, for apache httpd this is done by adding the www-data user to the video group:

 * echo 'SUBSYSTEM=="vchiq",GROUP="video",MODE="0660"' > /etc/udev/rules.d/10-vchiq-permissions.rules
 * usermod -aG video www-data

Feature set:
 * on the "browse" page:
  * browse through the media servers content (folders)
  * search (which can be done in djmount with some special ls _search/ filters
  * play file
  * add and remove items to the playlist
 * on the "control" page:
  * pause 
  * volume up and down
  * seek 30sec forward and backward
  * stop
  * show MP3 infos from tag
  * show what is currently played
  * show playlist
 * on the "settings" page:
  * the root directory
  * various omxplayer settings
  * turn on/off the ID3 parsing

Parts of the code were reused from https://github.com/JugglerLKR/omxplayer-web-controls-php, 
especially the trick how to send keys to a running omxplayer through a FIFO.

Open features:
 * remove and reorder entries in playlist on control page
 * next track on control page
 * pause in the header bar
 * image viewer and diashow
