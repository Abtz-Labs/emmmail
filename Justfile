default:
    @just --list

[doc('Start dev server on port 2525 (foreground)')]
start *args:
    #!/usr/bin/env bash
    port="2525"
    mode="foreground"
    for arg in {{args}}; do
        if [ "$arg" = "background" ]; then
            mode="background"
        elif [[ "$arg" =~ ^[0-9]+$ ]]; then
            port="$arg"
        fi
    done
    if [ -f .server.pid ] || [ "$(lsof -ti :$port | head -1)" != "" ]; then
        echo "Server already running."
        exit 0
    fi
    if [ "$mode" = "foreground" ]; then
        echo "Starting on http://localhost:$port (Ctrl+C to stop)"
        php -S localhost:$port index.php
    else
        php -S localhost:$port index.php > /dev/null 2>&1 &
        echo $! > .server.pid
        echo "Started on http://localhost:$port (PID: $(cat .server.pid))"
    fi

[doc('Stop dev server')]
stop:
    @if [ -f .server.pid ]; then \
        kill $(cat .server.pid) 2>/dev/null && echo "Server stopped" || echo "Server not running"; \
        rm -f .server.pid; \
    else \
        echo "Server not running"; \
    fi

[doc('Run test suite. With PRESET EMAIL [PASSWORD], runs real-server tests.')]
test *args:
    #!/usr/bin/env bash
    count=$(echo {{args}} | awk '{print NF}')
    if [ "$count" -eq 3 ]; then
        export EMMMAIL_TEST_PRESET=$(echo {{args}} | awk '{print $1}')
        export EMMMAIL_TEST_EMAIL=$(echo {{args}} | awk '{print $2}')
        export EMMMAIL_TEST_PASSWORD=$(echo {{args}} | awk '{print $3}')
    elif [ "$count" -eq 2 ]; then
        export EMMMAIL_TEST_PRESET=$(echo {{args}} | awk '{print $1}')
        export EMMMAIL_TEST_EMAIL=$(echo {{args}} | awk '{print $2}')
        read -s -p "Password: " EMMMAIL_TEST_PASSWORD
        echo
        export EMMMAIL_TEST_PASSWORD
    elif [ "$count" -ne 0 ]; then
        echo "Usage: just test [PRESET EMAIL [PASSWORD]]"
        echo "  PRESET    - provider name (gmail, yahoo, outlook, migadu, icloud)"
        echo "  EMAIL     - email address"
        echo "  PASSWORD  - account password (optional, prompted if omitted)"
        echo ""
        echo "Without arguments, real-server auth tests are skipped."
        exit 1
    fi
    php tests.php

[doc('Run manual sync for all accounts')]
sync:
    php index.php sync

[doc('Run first-time setup wizard')]
setup:
    just start background
    @echo "Setup wizard: http://localhost:2525/?setup=1"
