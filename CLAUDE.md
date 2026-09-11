# SAFE COMMAND
git status
git diff
git log
git branch
git show

php artisan route:list
php artisan about

composer show
npm list

npm run build
npm run test

# Potentially dangerous — only on local
php artisan migrate
php artisan db:seed

composer install
composer update

npm install
npm uninstall

git commit
git checkout <branch>

# Dangerous — never automatic
<!-- php artisan migrate:fresh
php artisan migrate:refresh
php artisan migrate:reset
php artisan db:wipe -->

git push
git push --force
git push --force-with-lease
git reset --hard
git clean -fd

## database destruction
DROP DATABASE
DROP TABLE
TRUNCATE
DELETE FROM ...

## system destruction
rm -rf
del /s
rmdir /s