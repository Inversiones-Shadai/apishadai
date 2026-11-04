# Seguimiento operativo de pilotos de escritorio

Desde la consola del backend del entorno que se quiere revisar:

```powershell
php artisan desktop-updates:pilot-status
php artisan desktop-updates:pilot-status --product=shpos --architecture=x64 --pending-minutes=30
php artisan desktop-updates:pilot-status --product=hq-client --devices
```

Comando de lectura para el operador con acceso a la consola/configuración del
servidor. No es un endpoint HTTP ni suplanta a un usuario de ShAdmin. No modifica
canales, permisos, licencias o auditoría; no emite enlaces temporales ni consulta
Cellar. El acceso a esta consola debe mantenerse restringido al operador del backend.

El JSON resume solo las activaciones actualmente asignadas al piloto vigente por
producto y arquitectura. Considera la última autorización de aplicar y el informe
vinculado a ese UUID, sin confundir consultas/descargas con instalación ni ocultar
intentos nuevos con informes antiguos recibidos tarde. Empates temporales se
clasifican como ambiguos. La salida sin `--devices` omite UUID de activaciones y
siempre excluye claves, nombres, URLs, rutas privadas y mensajes de error del equipo.

`pilot_results_complete` describe únicamente los resultados conocidos del grupo.
No acredita Windows limpio, prerrequisitos, estado online actual ni autoriza
producción. El umbral de pendientes es operativo, no un tiempo de despliegue.
`result_received_after_seconds` incluye arranque, red y demoras de reporte.

No se añade cron, alerta automática, migración ni permiso. Las acciones de ShAdmin
conservan sus ocho permisos individuales; estable sigue siendo una decisión
separada. El canal estable actual alcanza a todas las activaciones compatibles del
producto/arquitectura, no a un porcentaje configurado.

Procedimientos de incorporación, recuperación, firma, conservación y límites:
[operación de fase 8](../../../headquaters/hq-client/HQ-Client/docs/desktop-update-rollout.md).

Pruebas aisladas: `php artisan test --compact --filter=DesktopPilotStatusTest`.
