#!/bin/bash

cd ..

#SOURCE=$1
#if [ "$SOURCE" != "payxpert" ] && [ "$SOURCE" != "payzone" ]
#then
#    echo "Missing or incorrect white label: $SOURCE"
#    exit
#fi

VERSION=`git describe --tags 2>/dev/null | sed 's/^v//'`

# If not on a tag, the describe result will contain '-'
if [ "$VERSION" = "" ] || echo "$VERSION" | grep -q '-'; then
  # Try with local file
  if [ -f ".version" ]; then
    VERSION=`cat .version`
  fi

  if [ "$VERSION" = "" ]; then
    echo "Unable to determine version. Exiting"
    exit 1
  fi
fi

OUTPUT="output"
NAME="connect2pay-woocommerce-module-$VERSION"
rm -rf $OUTPUT/$NAME
mkdir -p $OUTPUT/$NAME
mkdir -p $OUTPUT/$NAME

# Embed dependencies
php composer.phar install

# Copy in destination
cp -a assets includes vendor gateway-payxpert.php "$OUTPUT/$NAME"

cd $OUTPUT/
zip -r "$NAME.zip" $NAME
echo "Done";
exit