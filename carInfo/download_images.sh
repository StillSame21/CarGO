#!/bin/bash

# Read the JSON file and download images from Unsplash
jq -c '.[]' cars.json | while read i; do
    IMAGE_NAME=$(echo $i | jq -r '.image')
    UNSPLASH_ID=$(echo $i | jq -r '.unsplash_id')
    echo "Downloading $IMAGE_NAME..."
    curl -sL "https://images.unsplash.com/photo-${UNSPLASH_ID}?w=800&q=80" -o "images/${IMAGE_NAME}"
done

echo "Download complete."
