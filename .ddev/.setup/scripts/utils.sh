#!/bin/bash

# Global variable to store spinner PID
SPINNER_PID=0

# Function to display a spinner at the current cursor position (simple colored style)
function _spinner() {
    local chars="|/-\\"
    local delay=0.1
    local i=0

    while true; do
        printf "\b\e[36m%c\e[0m" "${chars:$i:1}"
        ((i++))
        if [ $i -eq ${#chars} ]; then
            i=0
        fi
        sleep $delay
    done
}

function _progress() {

    printf "%s... " "$1"
    # Check if we're in CI environment or non-interactive terminal
    if [[ "$VERBOSE" -eq 0 ]] && [[ -z "$CI" ]] && [[ -z "$GITHUB_ACTIONS" ]] && [[ -z "$GITLAB_CI" ]] && [[ -z "$JENKINS_URL" ]] && [[ -t 1 ]]; then
      # Print initial space for spinner
      printf " "
      # Start spinner in background at current position
      _spinner &
      SPINNER_PID=$!
      # Save current stdout/stderr
#      exec 3>&1 4>&2
#      exec >/dev/null 2>&1
    else
      printf "\n"
    fi
}

function _done() {
    # Stop spinner if it was started
    if [ $SPINNER_PID -ne 0 ]; then
      kill $SPINNER_PID 2>/dev/null
      wait $SPINNER_PID 2>/dev/null
      SPINNER_PID=0
      # Restore stdout/stderr
      exec 1>&3 2>&4
      # Clear any remaining color codes and replace with checkmark
      printf "\b\e[0m\e[32m✔\e[39m\n"
    else
      # No spinner was running, just print checkmark
      printf "\e[32m✔\e[39m\n"
    fi
}


function get_lowest_supported_typo3_versions() {
    local TYPO3_VERSIONS_ARRAY=()
    IFS=' ' read -r -a TYPO3_VERSIONS_ARRAY <<< "$TYPO3_VERSIONS"
    if [ ${#TYPO3_VERSIONS_ARRAY[@]} -eq 0 ]; then
        message red "Error! No supported TYPO3 versions found in environment variables."
        exit 1
    fi
    printf "%s\n" "${TYPO3_VERSIONS_ARRAY[@]}" | sort -V | head -n 1
}

function get_supported_typo3_versions() {
    if [ -z "${TYPO3_VERSIONS+x}" ]; then
        message red "TYPO3_VERSIONS is unset. Please set it before running this function."
        return 1
    else
        local TYPO3_VERSIONS_ARRAY=()
        IFS=' ' read -r -a TYPO3_VERSIONS_ARRAY <<< "$TYPO3_VERSIONS"
        if [ ${#TYPO3_VERSIONS_ARRAY[@]} -eq 0 ]; then
            message red "Error! No supported TYPO3 versions found in environment variables."
            return 1
        fi
        printf "%s\n" "${TYPO3_VERSIONS_ARRAY[@]}"
    fi
}

function check_typo3_version() {
    local TYPO3=$1
    local SUPPORTED_TYPO3_VERSIONS=()
    local found=0

    if [ -z "$TYPO3" ]; then
        message red "No TYPO3 version provided. Please set one of the supported TYPO3 versions as argument."
        exit 1
    fi

    while IFS= read -r line; do
        SUPPORTED_TYPO3_VERSIONS+=("$line")
    done < <(get_supported_typo3_versions)

    for version in "${SUPPORTED_TYPO3_VERSIONS[@]}"; do
        if [[ "$version" == "$TYPO3" ]]; then
            found=1
            break
        fi
    done

    if [[ $found -eq 0 ]]; then
        message red "TYPO3 version '$TYPO3' is not supported."
        exit 1
    fi

    return 0
}

function pre_setup() {
  export VERSION=$1
  message magenta "Install TYPO3 $VERSION"
  _progress " ├─ Prepare environment"
    export BASE_PATH="/var/www/html/.Build/$VERSION"
    intro_typo3
    message blue "Pre Setup for TYPO3 $VERSION"
  _done
  install_start
  install_composer_packages
}

function post_setup() {
  cd $BASE_PATH
  TYPO3_INSTALL_DB_DBNAME=$DATABASE

  _progress " ├─ Setup TYPO3"
    if [ "$VERSION" == "13" ]; then
      post_setup_13
    elif [ "$VERSION" == "14" ]; then
      post_setup_14
    fi
  _done

  _progress " ├─ Import data"
    import_xml_data
    import_sql_data
  _done
  _progress " ├─ Update TYPO3"
    update_typo3
  _done
  printf " └─ \033[33mTYPO3 $VERSION setup completed!\033[0m Open in your browser: https://$VERSION.${EXTENSION_NAME}.ddev.site\n"
}

function intro_typo3() {
    message magenta "-------------------------------------------------"
    message magenta "|\t\t\t\t\t\t|"
    message magenta "| \t\t     TYPO3 $VERSION     \t\t|"
    message magenta "|\t\t\t\t\t\t|"
    message magenta "-------------------------------------------------"
}

function install_start() {
    rm -rf /var/www/html/.Build/$VERSION/*
    _progress " ├─ Setup environment"
      setup_environment
    _done
    _progress " ├─ Create symlinks"
      create_symlinks_main_extension
    _done
    _progress " ├─ Setup composer"
      setup_composer
    _done
}

function setup_environment() {
    rm -rf "$BASE_PATH"
    mkdir -p "$BASE_PATH/packages/$EXTENSION_KEY"
    chmod 775 -R $BASE_PATH
    export DATABASE="database_$VERSION"
    export TYPO3_BIN="$BASE_PATH/vendor/bin/typo3"
    mysql -uroot -proot -e "DROP DATABASE IF EXISTS $DATABASE"
}

function create_symlinks_main_extension() {
    local exclusions=(".*" "Documentation" "Documentation-GENERATED-temp" "var")
    for item in ./*; do
        local base_name=$(basename "$item")
        for exclusion in "${exclusions[@]}"; do
            if [[ $base_name == "$exclusion" ]]; then
                continue 2
            fi
        done
        ln -sr "$item" "$BASE_PATH/packages/$EXTENSION_KEY/$base_name"
    done
}

function setup_composer() {
    composer init --name="konradmichalik/typo3-$VERSION" --description="TYPO3 $VERSION" --no-interaction --working-dir "$BASE_PATH"
    composer config extra.typo3/cms.web-dir public --working-dir "$BASE_PATH"
    composer config repositories.packages path 'packages/*' --working-dir "$BASE_PATH"
    composer config --no-interaction allow-plugins.typo3/cms-composer-installers true --working-dir "$BASE_PATH"
    composer config --no-interaction allow-plugins.typo3/class-alias-loader true --working-dir "$BASE_PATH"
}

function setup_typo3() {
    cd $BASE_PATH
    export TYPO3_INSTALL_DB_DBNAME=$DATABASE
    $TYPO3_BIN configuration:set 'BE/debug' 1
    $TYPO3_BIN configuration:set 'FE/debug' 1
    $TYPO3_BIN configuration:set 'SYS/devIPmask' '*'
    $TYPO3_BIN configuration:set 'SYS/displayErrors' 1
    $TYPO3_BIN configuration:set 'SYS/trustedHostsPattern' '.*.*'
    $TYPO3_BIN configuration:set 'MAIL/transport' 'smtp'
    $TYPO3_BIN configuration:set 'MAIL/transport_smtp_server' 'localhost:1025'
    $TYPO3_BIN configuration:set 'GFX/processor' 'ImageMagick'
    $TYPO3_BIN configuration:set 'GFX/processor_path' '/usr/bin/'
}

function update_typo3() {
    $TYPO3_BIN database:updateschema
    $TYPO3_BIN cache:flush
}

function install_composer_packages() {
  _progress " ├─ Install composer packages"
    if [ "$VERSION" == "14" ]; then
      composer config repositories.typo3-console vcs git@github.com:konradmichalik/TYPO3-Console.git -d $BASE_PATH
    fi
    composer req typo3/cms-base-distribution:"^$VERSION" \
            typo3/cms-lowlevel:"^$VERSION" \
            $PACKAGE_NAME:'*@dev' \
            helhum/typo3-console:'* || dev-support-typo3-v14' \
            --no-progress -n -d $BASE_PATH
  _done
}

function import_xml_data() {
    PUBLIC_DIR="/var/www/html/.Build/${VERSION}/public"
    EXPORT_DIR="${PUBLIC_DIR}/fileadmin/user_upload/_temp_/importexport"
    FIXTURE_DIR="/var/www/html/Tests/Acceptance/Fixtures"

    mkdir -p $EXPORT_DIR

    for XML_FILE in "$FIXTURE_DIR"/*.xml; do
        if [ -f "$XML_FILE" ]; then
            FILENAME=$(basename "$XML_FILE")
            message yellow "Importing XML file $FILENAME..."
            cp "$XML_FILE" "$EXPORT_DIR/"
            $TYPO3_BIN impexp:import -vvv --force-uid "$EXPORT_DIR/$FILENAME"
        fi
    done

    if ! ls "$FIXTURE_DIR"/*.xml >/dev/null 2>&1; then
        message yellow "No XML files found in $FIXTURE_DIR. Skipping XML import."
    fi
}

function import_sql_data() {
    FIXTURE_DIR="/var/www/html/Tests/Acceptance/Fixtures"

    for DATA_FILE in "$FIXTURE_DIR"/*.sql; do
        if [ -f "$DATA_FILE" ]; then
            message yellow "Importing $DATA_FILE..."
            mysql -h db -u root -p"root" $DATABASE < "$DATA_FILE"
        else
          message yellow "No SQL files found in $FIXTURE_DIR. Import will be skipped."
        fi
    done
}

function post_setup_13 {
  mysql -h db -u root -p"root" -e "CREATE DATABASE $DATABASE;"
  $TYPO3_BIN  setup -n --dbname=$DATABASE --password=$TYPO3_DB_PASSWORD --create-site="https://${VERSION}.${EXTENSION_NAME}.ddev.site" --admin-user-password=$TYPO3_SETUP_ADMIN_PASSWORD
  setup_typo3

  sed -i "/'deprecations'/,/^[[:space:]]*'disabled' => true,/s/'disabled' => true,/'disabled' => false,/" /var/www/html/.Build/$VERSION/config/system/settings.php
}

function post_setup_14 {
  mysql -h db -u root -p"root" -e "CREATE DATABASE $DATABASE;"
  $TYPO3_BIN  setup -n --dbname=$DATABASE --password=$TYPO3_DB_PASSWORD --create-site="https://${VERSION}.${EXTENSION_NAME}.ddev.site" --admin-user-password=$TYPO3_SETUP_ADMIN_PASSWORD
  setup_typo3

  sed -i "/'deprecations'/,/^[[:space:]]*'disabled' => true,/s/'disabled' => true,/'disabled' => false,/" /var/www/html/.Build/$VERSION/config/system/settings.php
}

message() {
    local color=$1
    local message=$2

    case $color in
        red)
            echo -e "\033[31m$message\033[0m"
            ;;
        green)
            echo -e "\033[32m$message\033[0m"
            ;;
        yellow)
            echo -e "\033[33m$message\033[0m"
            ;;
        blue)
            echo -e "\033[34m$message\033[0m"
            ;;
        magenta)
            echo -e "\033[35m$message\033[0m"
            ;;
        cyan)
            echo -e "\033[36m$message\033[0m"
            ;;
        *)
            echo -e "$message"
            ;;
    esac
}
