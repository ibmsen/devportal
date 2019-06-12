#!/bin/bash -xe

BASEDIR=$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )
# if change the project name then also need to update the jenkins docker image webroot
PROJECT_NAME="devportal"
VERSION=2018.4.1.6
SERVICE="false"

BUILD_DIR="$BASEDIR/build"
TMP_DIR="$BASEDIR/tmp"

if [[ "$BRANCH_NAME" == 2018.* ]]
then
  SERVICE="true"
fi

# remove internal change control files so not included in the tgz
function remove_internal_files() {
  local MOD=$1
  rm -f ./modules/$MOD/.project
  rm -f ./modules/$MOD/.gitignore
  rm -rf ./modules/$MOD/.git
  rm -rf ./modules/$MOD/.idea
  rm -rf ./modules/$MOD/travis-ci
  rm -f ./modules/$MOD/.travis.yml
  rm -f ./modules/$MOD/getdeps.sh
  rm -rf ./modules/$MOD/tmp
}
function remove_internal_files_theme() {
  local MOD=$1
  rm -f ./themes/$MOD/.project
  rm -f ./themes/$MOD/.gitignore
  rm -rf ./themes/$MOD/.git
  rm -rf ./themes/$MOD/.idea
  rm -rf ./themes/$MOD/travis-ci
  rm -f ./themes/$MOD/.travis.yml
}

if [[ -z ${BUILD_TIMESTAMP:-} ]]; then
  echo "BUILD_TIMESTAMP is unset. We need this to tag the template tgz"
  exit 1
fi

mkdir -p $BUILD_DIR

cd $BUILD_DIR

# Get composer
if [[ ! -f "composer.phar" || -z $(php composer.phar -n -V) ]]
then
  EXPECTED_SIGNATURE=$(wget -q -O - https://composer.github.io/installer.sig)
  php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
  ACTUAL_SIGNATURE=$(php -r "echo hash_file('SHA384', 'composer-setup.php');")
  php -r "if (\"$EXPECTED_SIGNATURE\" === \"$ACTUAL_SIGNATURE\") { echo 'Installer verified'; } else { echo 'Installer corrupt'; unlink('composer-setup.php'); } echo PHP_EOL;"

  php composer-setup.php
  php -r "unlink('composer-setup.php');"
fi

export COMPOSER_EXIT_ON_PATCH_FAILURE=1
php composer.phar -n config -g github-oauth.github.ibm.com 23b3b2c497a3fa8cc59a15fc69e35a7e1b3fd079

# Create new composer project
rm -rf $PROJECT_NAME
php composer.phar -n create-project drupal-composer/drupal-project:8.x-dev $PROJECT_NAME --stability dev --no-interaction --no-install

cp -f $BASEDIR/composer.json $PROJECT_NAME

# copy auth.json file if it exists
if [[ -f "$BASEDIR/auth.json" ]]
then
  cp $BASEDIR/auth.json $PROJECT_NAME/auth.json
fi

# This installs our 3rdparty dependencies from composer.json
cd $PROJECT_NAME
php ../composer.phar -n -v install

if [[ ! -f "$PROJECT_NAME/autoload.php" ]]
then
  cp $BASEDIR/composer-stub/autoload.php $BUILD_DIR/$PROJECT_NAME/autoload.php
fi

# have to do install first and then update with lock file or we dont seem to get any php files
# seems to be an autoload issue
cp -f $BASEDIR/composer.lock $BUILD_DIR/$PROJECT_NAME
php ../composer.phar -n -v install

#Apply patches
cd $BUILD_DIR
for PATCH in $(cd ../patches && ls -1)
do
  patch -f --directory $PROJECT_NAME -p0 -i ../../patches/$PATCH
done

# remove files legal have issues with
rm -rf $PROJECT_NAME/vendor/youshido/graphql/examples/js-relay

cd $PROJECT_NAME

# Add our modules
php ../composer.phar -n require --prefer-dist drupal/apictest:1.2.* drupal/ghmarkdown:1.0.* drupal/socialblock:1.0.* drupal/featuredcontent:1.0.* drupal/ibm_apim:1.2.* drupal/mail_subscribers:1.0.* drupal/auth_apic:1.1.* drupal/apic_api:1.0.* drupal/apic_app:1.4.* drupal/consumerorg:1.1.* drupal/product:1.0.* drupal/themegenerator:1.0.* drupal/connect_theme:1.0.*

# copy installation profile
mkdir -p $BUILD_DIR/$PROJECT_NAME/profiles
cp -rf $BASEDIR/apim_profile $BUILD_DIR/$PROJECT_NAME/profiles

#delete drush
php ../composer.phar -n -v remove drush/drush
rm -rf $BUILD_DIR/$PROJECT_NAME/drush

echo "version: '$VERSION'" > $BUILD_DIR/$PROJECT_NAME/profiles/apim_profile/apic_version.yaml
echo "build: '$BUILD_TIMESTAMP'" >> $BUILD_DIR/$PROJECT_NAME/profiles/apim_profile/apic_version.yaml

mkdir -p $BUILD_DIR/$PROJECT_NAME/libraries
cp -rf $BASEDIR/libraries/* $BUILD_DIR/$PROJECT_NAME/libraries

# copy site stub files needed by aegir
cp $BASEDIR/composer-stub/sites/* $BUILD_DIR/$PROJECT_NAME/sites

# copy our overrides
cp -rf $BASEDIR/overrides/* $BUILD_DIR/$PROJECT_NAME

mkdir -p $TMP_DIR
cd $TMP_DIR
chmod a+x $BASEDIR/getdeps.sh
${BASEDIR}/getdeps.sh ${TMP_DIR} ${SERVICE} ${RELEASE} "true"

mkdir -p $BUILD_DIR/$PROJECT_NAME/modules/apic_api/explorer
cp -rf $TMP_DIR/explorer/package/dist/* $BUILD_DIR/$PROJECT_NAME/modules/apic_api/explorer
mkdir -p $BUILD_DIR/$PROJECT_NAME/modules/ibm_apim/analytics
cp -rf $TMP_DIR/analytics/package/dist/* $BUILD_DIR/$PROJECT_NAME/modules/ibm_apim/analytics

# translations
mkdir -p $BUILD_DIR/$PROJECT_NAME/sites/all/translations
# hiding the logging of this copy as it fills the log with thousands of lines of output otherwise.
set +x
cp $BASEDIR/po_files/* $BUILD_DIR/$PROJECT_NAME/sites/all/translations
set -x

# compile SCSS
cd $BUILD_DIR/$PROJECT_NAME/themes/connect_theme
php compile-scss.php

cd $BUILD_DIR/$PROJECT_NAME

# remove the tags vocabulary file from consumerorg since its in our installation profile
# has to be in consumerorg module for travis tests to pass
rm -f $BUILD_DIR/$PROJECT_NAME/modules/consumerorg/config/install/taxonomy.vocabulary.tags.yml

# remove robots.txt as can be done from within the site now
rm -f $BUILD_DIR/$PROJECT_NAME/robots.txt
rm -f $BUILD_DIR/$PROJECT_NAME/README.txt
rm -f $BUILD_DIR/$PROJECT_NAME/web.config

remove_internal_files ghmarkdown
remove_internal_files socialblock
remove_internal_files featuredcontent
remove_internal_files ibm_apim
remove_internal_files api
remove_internal_files application
remove_internal_files product
remove_internal_files consumerorg
remove_internal_files auth_apic
remove_internal_files themegenerator
remove_internal_files apictest
remove_internal_files mail_subscribers
remove_internal_files_theme connect_theme

cd $BUILD_DIR
cp ../sqlexports/translations.sql devportal/

tar -zcf ibm_apim_devportal-8.x-$VERSION-$BUILD_TIMESTAMP.tgz $PROJECT_NAME
