@echo off
setlocal
title SvityazHOME Localhost

set "ROOT=%~dp0"
if "%ROOT:~-1%"=="\" set "ROOT=%ROOT:~0,-1%"
set "PORT=8000"
set "HOST=localhost"
set "URL=http://%HOST%:%PORT%/"
set "START_PAGE=local.html"
set "OPEN_URL=%URL%%START_PAGE%"

set "PHP_BIN=php"
where php >nul 2>nul
if errorlevel 1 (
  if exist "C:\php\php.exe" (
    set "PHP_BIN=C:\php\php.exe"
  ) else (
    echo [ERROR] PHP не знайдено. Додайте php до PATH або встановіть C:\php\php.exe
    pause
    exit /b 1
  )
)

echo.
echo Root: %ROOT%
echo URL : %URL%
echo Open: %OPEN_URL%
echo.
start "" "%OPEN_URL%"
"%PHP_BIN%" -S %HOST%:%PORT% -t "%ROOT%" "%ROOT%\router.php"

endlocal
