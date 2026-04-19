@echo off
if not "%~1"=="RELAUNCHED" start "ElementsKit Icon Pack Sync" /max cmd /k ""%~f0" RELAUNCHED" & exit /b
mode con cols=220 lines=50
REM Batch wrapper to run iconpack-sync.php
REM Double-click this file to run the PHP script. Requires PHP installed (php.exe in PATH or common locations).

rem Try php in PATH
where php >nul 2>&1
if %errorlevel%==0 (
  set "PHP=php"
) else (
  if exist "%ProgramFiles%\PHP\php.exe" (
    set "PHP=%ProgramFiles%\PHP\php.exe"
  ) else if exist "%ProgramFiles(x86)%\PHP\php.exe" (
    set "PHP=%ProgramFiles(x86)%\PHP\php.exe"
  )
)

if not defined PHP (
  echo PHP executable not found in PATH or common locations.
  echo Please install PHP or edit this file and set PHP path to your php.exe.
  pause
  exit /b 1
)

set "SCRIPT_DIR=%~dp0"
"%PHP%" "%SCRIPT_DIR%Systems File\iconpack-sync.php" %*

pause
