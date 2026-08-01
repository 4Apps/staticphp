#!/bin/bash

PLATFORM=`uname`

# Find base path
BASE_PATH=$(dirname $(readlink -f "$0"))/..
BASE_PATH="`cd $BASE_PATH;pwd`"

# Git stuff
COMMIT="HEAD"
LOCAL_BRANCH="`git name-rev --name-only HEAD`"
TRACKING_REMOTE="`git config branch.$LOCAL_BRANCH.remote`"
TRACKING_BRANCH="$TRACKING_REMOTE/$LOCAL_BRANCH"


# Test non-ascii filenames
echo "*Testing non-ascii filenames.. "
if [ $(git diff --cached --name-only --diff-filter=A -z $COMMIT | LC_ALL=C tr -d '[ -~]\0' | wc -c) -gt 0 ]; then
    echo "Error: Attempt to add a non-ascii file name."
    echo
    echo "This can cause problems if you want to work"
    echo "with people on other platforms."
    echo
    echo "To be portable it is advisable to rename the file ..."
    echo
    exit 1
fi
echo " Done"
echo


# Test for most common debug symbols
#echo "*Testing for debug symbols.. "
#if [ "$(git diff --cached $COMMIT | grep -P 'print_r|console\\.log')" != "" ]; then
#    echo "!!! ERROR !!!"
#    echo "$(git diff --cached $COMMIT | grep -P 'print_r|console\\.log')"
#    exit 1
#fi
#echo " Done"
#echo


# Run the same checks CI runs, so a green commit means a green pipeline.
# Asset builds used to happen here, which made every commit take 20+ seconds and put
# generated files in the commit; that belongs in the build, not the hook.
echo "*Running code tests.. "
if [ -f /.dockerenv ]; then
    ./scripts/code_tests.bash
else
    docker compose run --rm develop /srv/app/scripts/code_tests.bash
fi
if [ "$?" != "0" ]; then
    echo "!!! ERROR: code tests failed !!!"
    exit 1
fi
echo " Done"
echo


# Test for whitespace errors
echo "*Testing for whitespace errors.. "
git diff-index --cached --check $COMMIT --
if [ "$?" != "0" ]; then
    echo "!!! ERROR !!!"
    exit 1
fi
echo " Done"
echo
