copy zip32.dll %windir%\system32\
copy Unzip32.dll %windir%\system32\
copy CGZipLibrary.dll %windir%\system32\
copy MSSTDFMT.DLL %windir%\system32\
copy COMCT332.OCX %windir%\system32\
copy comdlg32.ocx %windir%\system32\
copy implode.dll %windir%\system32\
copy dao360.dll %windir%\system32\
copy MSADODC.OCX %windir%\system32\
copy MSCOMCT2.OCX %windir%\system32\
copy MSCOMCTL.OCX %windir%\system32\
copy MSCOMM32.OCX %windir%\system32\
copy MSDATGRD.OCX %windir%\system32\
copy MSDATLST.OCX %windir%\system32\
copy TABCTL32.OCX %windir%\system32\
copy inpout32.dll %windir%\system32\
copy stdole2.tlb %windir%\system32\
copy wininet.dll %windir%\system32\
copy scrrun.dll %windir%\system32\
copy Co2c40en.dll %windir%\system32\
copy odbccp32.dll %windir%\system32\
copy odbcbcp.dll %windir%\system32\
copy ODBCINST.DLL %windir%\system32\
copy msvbvm60.dll %windir%\system32\
copy MSBIND.DLL %windir%\system32\
copy msjro.dll %windir%\system32\
copy RICHTX32.OCX %windir%\system32\
copy VB6STKIT.DLL %windir%\system32\
copy MSFLXGRD.OCX %windir%\system32\
copy SDX.dll %windir%\system32\
copy SDXControl.ocx %windir%\system32\
copy MSDBRPTR.dll %windir%\system32\
copy EventVB_I.dll %windir%\system32\

rem copy crpe32.dll %windir%\system32\
rem  copy crystl32.ocx %windir%\system32\

rem  regsvr32 /s crpe32.dll
rem  regsvr32 /s crystl32.ocx
 
regsvr32 /s %windir%\system32\EventVB_I.dll 
regsvr32 /s %windir%\system32\zip32.dll 
regsvr32 /s %windir%\system32\Unzip32.dll 
regsvr32 /s %windir%\system32\CGZipLibrary.dll 
regsvr32 /s %windir%\system32\MSDBRPTR.dll
regsvr32 /s %windir%\system32\SDXControl.ocx
regsvr32 /s %windir%\system32\SDX.dll
regsvr32 /s %windir%\system32\MSFLXGRD.OCX
regsvr32 /s %windir%\system32\VB6STKIT.DLL
regsvr32 /s %windir%\system32\RICHTX32.OCX
regsvr32 /s %windir%\system32\msjro.dll
regsvr32 /s %windir%\system32\MSSTDFMT.DLL
regsvr32 /s %windir%\system32\COMCT332.OCX
regsvr32 /s %windir%\system32\comdlg32.ocx
regsvr32 /s %windir%\system32\implode.dll
regsvr32 /s %windir%\system32\DAO350.DLL
regsvr32 /s %windir%\system32\dao360.dll
regsvr32 /s %windir%\system32\MSADODC.OCX
regsvr32 /s %windir%\system32\MSCOMCT2.OCX
regsvr32 /s %windir%\system32\MSCOMCTL.OCX
regsvr32 /s %windir%\system32\MSCOMM32.OCX
regsvr32 /s %windir%\system32\MSDATGRD.OCX
regsvr32 /s %windir%\system32\MSDATLST.OCX
regsvr32 /s %windir%\system32\TABCTL32.OCX
regsvr32 /s %windir%\system32\inpout32.dll
regsvr32 /s %windir%\system32\stdole2.tlb
regsvr32 /s %windir%\system32\wininet.dll
regsvr32 /s %windir%\system32\scrrun.dll
regsvr32 /s %windir%\system32\Co2c40en.dll
regsvr32 /s %windir%\system32\odbccp32.dll
regsvr32 /s %windir%\system32\odbcbcp.dll
regsvr32 /s %windir%\system32\ODBCINST.DLL
regsvr32 /s %windir%\system32\ODBCINT.DLL
regsvr32 /s %windir%\system32\msvbvm60.dll
regsvr32 /s %windir%\system32\MSBIND.DLL
MDAC_TYP.EXE
copy database.mdb c:\
DSN.exe