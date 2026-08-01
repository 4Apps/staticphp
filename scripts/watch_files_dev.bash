#!/bin/bash

if [ $1 == "all" ]; then
    inotifywait -r -m -e close_write -e attrib -e moved_to -e moved_from -e create -e delete --exclude "./node_modules/*|./vendor/*" . | {
        while read -r directory events filename; do
            echo "File '$filename' changed in directory '$directory' events '$events'"
            npm run files:sync:to_host
        done
    }
elif [ $1 == "css" ]; then
    inotifywait -r -m -e close_write -e attrib -e moved_to -e moved_from -e create -e delete --exclude "./node_modules/*|./vendor/*" . | {
        while read -r directory events filename; do
            if [[ "$filename" =~ \.scss$ ]]; then
                echo "SCSS File '$filename' changed in directory '$directory' with events: '$events'"
                npm run css:build
            fi
        done
    }
else
    echo "Invalid argument, use 'all' or 'css'"
fi
