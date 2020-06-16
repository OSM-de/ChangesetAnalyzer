# ChangesetAnalyzer
Analyse all your OSM Changesets if the objects have changed

## Prerequisits
The script [changeset-analyzer.php](changeset-analyzer.php) will try to create a subdirectory called `changeset-analyzer-$USERNAME`, where `$USERNAME` is either posted by a HTML form with a parameter called `display` or as the first argument when it is called from the command-line.

To allow for this, the user running the script must be allowed to create directories. For Linux the files and the directory should be `chown`'d to the same user and group and thus the standard `chmod` of 755 should be enough to make the script work.
