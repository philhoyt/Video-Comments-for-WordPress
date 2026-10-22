#!/bin/zsh
# WP-CLI against the `climbchippewa06` Local site. Local's MySQL listens on a socket,
# not TCP, so point mysqli at it. Recreate the symlink if Local changes the
# site id: ln -sfn "~/Library/Application Support/Local/run/<id>/mysql/mysqld.sock" ~/.local-sockets/climbchippewa06.sock
php -d memory_limit=512M -d mysqli.default_socket="$HOME/.local-sockets/climbchippewa06.sock" -d error_reporting="E_ALL & ~E_DEPRECATED" "$(command -v wp)" --path="$HOME/Local Sites/climbchippewa06/app/public" "$@"
