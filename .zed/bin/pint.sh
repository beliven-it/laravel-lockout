#!/bin/bash

function cleanup() {
    rm "$temp_file"
}

trap cleanup EXIT

# Create a temporary file
temp_file="/tmp/_temp_pint.php"

# Read STDIN buffer and save it to the temporary file

cat >"$temp_file"

# Run the pint command
./vendor/bin/pint -q --config="./pint.json" "$temp_file"

# Read the modified file and return its contents
cat "$temp_file"
