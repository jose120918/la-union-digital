# Sistema La Unión Digital

Plugin de WordPress para administrar el fondo de inversión **La Unión**. Centraliza el registro de socios, sus aportes, créditos, recaudos, utilidades y la tesorería operativa. Este README cubre todo el código actual y sirve como guía ejecutiva y técnica.

## Visión general
- **Qué hace:** habilita a la organización para registrar socios, cobrar aportes obligatorios, recibir comprobantes de pago desde el frontend, gestionar solicitudes de crédito (con firma digital de solicitante y deudor solidario), y operar tesorería con controles de liquidez, desembolsos y cierres.
- **Dónde corre:** como plugin de WordPress. Usa la base de datos existente (`$wpdb`) y shortcodes para el frontend. Los activos estáticos viven en `assets/` y el núcleo en `la-union-core.php`.
- **Roles:**
  - `lud_socio`: acceso básico a shortcodes de autoservicio (pagos, simulador, historial, perfil, registro).
  - `lud_secretaria`: solo lectura de tesorería (`lud_view_tesoreria`).
  - `lud_tesorero`: controla operaciones (`lud_manage_tesoreria`, subir archivos).
  - `lud_presidente`: supervisa y gestiona (`lud_manage_tesoreria`).
  - `administrator`: recibe capacidades de tesorería automáticamente.

## Estructura del plugin
- `la-union-core.php`: declara constantes de ruta, carga clases, registra hooks globales y crea roles.
- `includes/class-db-installer.php`: instala tablas personalizadas con `dbDelta` en la activación.
- `includes/class-security.php`: endpoint seguro para servir comprobantes almacenados en carpeta protegida.
- `includes/class-module-transacciones.php`: formulario y lógica de reporte de pagos desde el frontend.
- `includes/class-module-creditos.php`: simulador, solicitud y flujo de aprobación de créditos (solicitante + deudor).
- `includes/class-module-retiros.php`: solicitudes de retiro voluntario (paz y salvo obligatorio) y cálculo del monto estimado a devolver.
- `includes/class-frontend-shortcodes.php`: shortcodes de autoservicio (resumen, historial, beneficiario, registro de socio, retiro).
- `includes/class-admin-tesoreria.php`: panel administrativo (dashboard, desembolsos, cierres, cambios de acciones, gestión de socios, retiros).
- `includes/class-debug-tools.php`: utilidades de depuración (solo roles con privilegios altos).
- `includes/class-module-importaciones.php`: importaciones masivas de socios, aportes históricos y créditos desde CSV/XLSX.
- `assets/css/lud-style.css`: estilos compartidos para tarjetas, formularios y listados.

## Instalación y activación
1. Copiar el directorio del plugin a `wp-content/plugins/sistema-la-union-digital/`.
2. Activar desde el administrador de WordPress. Durante la activación se crean las tablas personalizadas y los roles con capacidades.
3. Asegurar que la carpeta de uploads permita crear subdirectorios (`fondo_seguro/`, `fondo_seguro/firmas/`, `fondo_seguro/documentos/`, `fondo_seguro/contratos/`).

## Tablas personalizadas
Creación gestionada por `LUD_DB_Installer`:
- `fondo_cuentas`: ficha financiera de cada socio, datos personales, beneficiario, estado y banderas como `permite_galeria` para comprobantes.
- `fondo_transacciones`: pagos reportados (aporte, cuota, multa, gasto, etc.) con estados y comprobantes.
- `fondo_creditos`: solicitudes y créditos activos con tipo (corriente/ágil), montos, plazos, firmas, tracking, estado y metadatos forenses (IP, user agent).
- `fondo_amortizacion`: tabla de cuotas programadas y pagadas por crédito.
- `fondo_gastos`: gastos operativos de la tesorería.
- `fondo_recaudos_detalle`: desglose de recaudos por concepto (ahorro, multa, intereses, capital, etc.).
- `fondo_utilidades_mensuales`: utilidades asignadas y liquidadas a cada socio por mes/año.
- `fondo_retiros`: solicitudes de retiro voluntario con estado, monto estimado, usuario que responde, fecha y motivo de respuesta.

## Shortcodes disponibles (frontend)
- `[lud_reportar_pago]` (`LUD_Module_Transacciones::render_form_pago`):
  - Calcula deuda administrativa y de créditos para sugerir monto mínimo.
  - Obliga captura con cámara salvo que `permite_galeria` sea 1.
  - Valida máximo pagable (evita ahorro voluntario) y sube comprobante seguro a `uploads/fondo_seguro/`.
  - Registra transacción en estado pendiente.
- `[lud_simulador_credito]` (`LUD_Module_Creditos::render_simulador`):
  - Verifica sanciones por mora (90 días), liquidez disponible y regla del 70% para refinanciación.
  - Simula corrientes (hasta 36 meses, tasa 2%) y ágiles (1 mes, tasa 1.5%) usando amortización alemana (capital constante e interés sobre saldo).
  - Bloquea solicitudes de crédito corriente cuya cuota resultante sea menor a $50.000 (alerta visual y validación backend, conforme estatutos).
  - Calcula y muestra un score de pago (0-100) con barra de viabilidad basada en cuotas pagadas y moras; se usa para priorizar la liberación de la cola de liquidez.
  - Incluye tooltip que explica el cálculo del score (cuotas pagadas vs. cuotas en mora y créditos terminados) para que el socio entienda el orden de prioridad.
  - La cola de liquidez libera primero a socios con mejor score y luego respeta la fecha de llegada.
  - Bloquea corrientes en diciembre (Art. 8.1) y evita refinanciar un crédito que ya fue refinanciado una vez.
  - Solicita firma digital del socio y deudor solidario (canvas) y genera tokens de seguimiento.
  - Si la liquidez es insuficiente, registra la solicitud en una fila de espera y la libera automáticamente a Tesorería en cuanto haya cupo, manteniendo el orden de llegada.
- `[lud_zona_deudor]`: área donde el codeudor visualiza y firma la solicitud, cambiando el crédito a `pendiente_tesoreria`.
- `[lud_resumen_ahorro]`: tarjeta de ahorro con estado “Al día/Pendiente”, deudas calculadas y rendimientos anuales.
- En “Mi Ahorro” se desglosan las deudas por concepto (ahorro, administración, intereses, mora, multas, otros) mostrando solo rubros con saldo > 0 en una lista compacta; cada rubro indica días de atraso y su valor mensual base. El rubro de multas trae un tooltip que explica que se cobra $1.000 por acción y por día después del día 5, acumulando mes a mes hasta registrar el pago.
- Si el socio tiene créditos vigentes (activos, en mora o pendientes de desembolso) se muestra una tarjeta adicional con monto aprobado, cuota estimada y fecha objetivo de cierre (se recalcula si hay refinanciación) justo debajo del bloque de acciones/rendimientos.
- `[lud_historial]`: últimos movimientos del socio con notas, estados y desglose aprobado.
- Historial con filtros por fecha, conceptos legibles, paginación AJAX y tarjetas compactas a dos columnas con badge de estado, monto a la derecha e identificador de movimiento más acceso al comprobante.
- Incluye desembolsos de crédito como movimiento aprobado con enlace al contrato PDF y muestra las actualizaciones de datos como eventos sin monto con botón “Ver cambios” (abre modal con los campos editados). Los comprobantes/contratos se abren en pestaña nueva usando el endpoint seguro, evitando lightbox de terceros y errores 403.
- `[lud_perfil_datos]`: captura y guarda beneficiario (cumplimiento estatutario art. 22).
- `[lud_registro_socio]`: formulario de ingreso para nuevos socios, incluyendo PDF de identidad y datos KYC.
- `[lud_retiro_voluntario]` (`LUD_Module_Retiros::render_formulario_retiro`):
  - Solo permite solicitar retiro si el socio está paz y salvo (sin deudas administrativas ni créditos activos).
  - Calcula el monto estimado a devolver (ahorro + rendimientos asignados) y registra la solicitud como `pendiente`.
  - Bloquea solicitudes duplicadas y exige aceptar las condiciones de reingreso (2 meses después del retiro).

## Flujo de pagos (frontend a tesorería)
1. Socio inicia sesión y usa `[lud_reportar_pago]`.
2. El módulo valida monto máximo según deuda administrativa + créditos y guarda comprobante en zona segura.
3. Se inserta transacción en `fondo_transacciones` con estado `pendiente` y detalle de preferencia de abono (si excede cuota y tiene crédito).
4. Tesorería revisa en el dashboard y aprueba/rechaza (ver sección Tesorería).

## Flujo de créditos
1. Socio abre `[lud_simulador_credito]` y pasa validaciones (sanciones, liquidez, regla 70%).
2. Ingresa monto, plazo, deudor solidario y firma digital. Se guarda firma en `uploads/fondo_seguro/firmas/` y se registra en `fondo_creditos` como `pendiente_deudor`.
3. Se envía correo al deudor solidario con token (`codigo_seguimiento`).
4. Deudor firma en `[lud_zona_deudor]`; el crédito pasa a `pendiente_tesoreria` con fecha de aprobación de deudor.
5. Si en el paso 1 no había liquidez suficiente, la solicitud queda en `fila_liquidez` y se promueve automáticamente a `pendiente_tesoreria` en cuanto el cupo del fondo lo permite, respetando el orden de solicitud.
6. Tesorería desembolsa, genera contrato PDF robusto (si TCPDF está disponible) con huella forense y avanza estado. También crea el pagaré con su carta de instrucciones firmado por deudor y deudor solidario.
7. Se registra el desembolso como movimiento aprobado en el historial del socio, adjuntando contrato y pagaré/carta para descarga segura.

## Contratos y títulos valor
- El contrato de mutuo se genera como PDF con cláusulas de aceleración, imputación de pagos, reporte a centrales y mérito ejecutivo. Incluye datos del crédito (monto, tasa, plazo, IP y agente) y firmas del solicitante y deudor solidario.
- El pagaré se acompaña de la carta de instrucciones en un mismo PDF, firmado por ambos. El valor se calcula con capital + intereses estimados y fecha de vencimiento estimada (día 5 según acta del 21 de septiembre de 2024).
- Ambos archivos se guardan en `uploads/fondo_seguro/contratos/` y se registran en el movimiento de desembolso para descarga segura desde el historial y Tesorería.

## Panel de Tesorería
Implementado en `LUD_Admin_Tesoreria` (menú “💰 Tesorería” para roles con `lud_view_tesoreria`):
- **Dashboard general** (`view=dashboard`): KPIs de caja, intereses, multas, reservas de secretaría, disponibilidad para créditos, y paneles de aprobación. Incluye Caja Secretaría con el recaudo del mes y un histórico de entregas mensuales.
  - La caja y el disponible para prestar se calculan con el recaudo del **año en curso** y el saldo vigente de créditos, evitando sumar años cerrados.
- **Desembolsos y cierres:**
  - Aprobación/rechazo de pagos (`admin_post_lud_aprobar_pago`, `lud_rechazar_pago`).
  - Desembolso de créditos (`admin_post_lud_aprobar_desembolso`).
  - Liquidación anual de utilidades (`admin_post_lud_liquidacion_anual`).
- **Retiros voluntarios:**
  - Card de “📤 Solicitudes de Retiro” en el dashboard que lista retiros `pendiente`.
  - Botón para aprobar y agendar la entrega; botón para rechazar obligando a escribir el motivo (registrado en BD).
- **Gestión de socios:**
  - Buscador y detalle de socio (`view=buscar_socio`, `view=detalle_socio`).
  - Editor de ficha (`view=editar_socio`) con cambios de acciones, actualización de estado y datos.
  - Programación de cambios de acciones aplicados automáticamente en `ejecutar_cambios_programados`.
  - Aprobación o rechazo de registros entrantes (`lud_aprobar_registro`, `lud_rechazar_registro`).
  - Entregas de secretaría (`lud_entregar_secretaria`) para reflejar salida de caja de ese concepto.
- **Presidencia** (`view=presidencia`): panel exclusivo para aprobar o rechazar solicitudes de ingreso pendientes, con motivo obligatorio al rechazar, historial de decisiones y acceso al PDF cargado por el solicitante.
- **Control de asistencia** (`view=control_asistencia`): pestaña para marcar presentes/ausentes en la asamblea; los ausentes reciben una multa pendiente de $10.000 con detalle “Inasistencia Asamblea (fecha)”.
- **Historial de intereses:** consulta de utilidades liquidadas (`view=historial_intereses`).
- **Históricos anuales** (`view=historial_anual`): resumen anual por concepto (ahorro, capital, intereses, multas, secretaría y cuota mixta).
- **Importaciones** (`view=importaciones`): carga masiva de socios y movimientos históricos, además de créditos vigentes desde XLSX con tabla de amortización.
- **Configuración del fondo (solo administradores):** pestaña “⚙️ Configuración del Fondo” con dos bloques:
  - **Configurador de correos:** define URL de logo, enlaces de portal/políticas/actualización de datos, nombre de remitente y pie global de todos los correos automáticos.
  - **LUD Test:** formulario para enviar un correo de prueba y validar la plantilla/SMPP activo.
- **Avisos visuales compactos:** las alertas de éxito/error en shortcodes (pagos, ahorro, simulador, retiros) usan tipografía reducida y colores suaves para no distraer al usuario.
- **Seeding de datos de prueba:** en “🧪 LUD Tests” (solo administradores técnicos) hay botones para “Sembrar Datos de Prueba” (crea 33 socios con ahorros, créditos, moras controladas e historial simulado). Los pagos sembrados se registran en el día 5 de cada mes y sincronizan `fecha_ultimo_aporte` con el último pago generado para evitar incoherencias de mora. “Limpiar Datos de Prueba” elimina únicamente esos usuarios y sus tablas relacionadas.
- **Vista previa legal:** en “🧪 LUD Tests” puedes enviar a un correo indicado un contrato de mutuo y su pagaré con carta de instrucciones generados con TCPDF y datos ficticios (no crea desembolsos reales).
- **Dashboard Tesorería:** lista de morosos ordenada A-Z, Caja Secretaría con recaudo del mes e histórico de entregas, y ficha de socio con fecha de incorporación y estado detallado de mora/al día.

## Reglas y límites vigentes
- Máximo 10 acciones por socio: la UI y el backend bloquean cantidades superiores al programar cambios desde Tesorería.
- Admisiones sin límite técnico de cupos; todas las solicitudes entran como “Pendiente” hasta ser aprobadas o rechazadas por Presidencia.
- Refinanciación única por crédito: si un crédito ya fue refinanciado, el sistema bloquea nuevos intentos y marca el origen en `datos_entrega`.
- Créditos corrientes no se radican en diciembre (Art. 8.1); solo se permiten ágiles con aviso de entrega diferida.
- El score de pago (0-100) prioriza la cola de liquidez y se muestra al socio antes de radicar la solicitud.
- Solicitud de retiro: se bloquea el formulario si el socio no está paz y salvo (deuda administrativa o créditos pendientes).

## Notificaciones automáticas y correos
- Motor centralizado en `LUD_Notificaciones` con plantilla HTML unificada (saludo obligatorio con nombre + tipo/número de identificación).
- Correos automáticos actuales:
  - Pago reportado, pago aprobado (con desglose) o pago rechazado (motivo).
  - Solicitud de crédito radicada, correo al deudor solidario con enlace de firma, desembolso/contrato firmado (adjunto PDF) y actualizaciones de estado.
  - Actualización de datos (zona de socios o panel administrativo) y recordatorio para actualizarlos cada 6 meses.
  - Solicitud de retiro voluntario y respuesta (aprobado/rechazado).
  - Recordatorios diarios de mora (1 correo por día en mora efectiva) con saldo actualizado.
  - Resumen mensual a Presidencia, Secretaría y Tesorería (día 1: cierre del mes anterior con métricas).
- La configuración editable vive en la pestaña “⚙️ Configuración del Fondo” del administrador y se almacena en `wp_options` (`lud_ajustes_correos`).
- Tareas programadas:
  - `lud_tarea_correos_diarios`: recordatorios de mora.
  - `lud_tarea_recordatorio_datos`: recordatorios de actualización de datos (si pasaron 6 meses y no hubo recordatorio en 30 días).
  - `lud_tarea_resumen_directiva`: ejecuta a diario pero solo envía el resumen el primer día de cada mes.

## Seguridad y privacidad
- Bloqueo de acceso directo mediante `ABSPATH` en todos los archivos.
- Comprobantes y firmas se almacenan en `uploads/fondo_seguro/` y se sirven solo vía `admin_post_lud_ver_comprobante`, validando permisos (`administrator`, `lud_manage_tesoreria`, `lud_view_tesoreria`) o pertenencia del socio al archivo (propietario de la transacción o documento), evitando 403 y lightbox de constructores externos.
- Validaciones de nonce en todos los formularios (`wp_verify_nonce` / `check_admin_referer`).
- Sanitización de entradas (`sanitize_text_field`, `sanitize_email`, `wp_check_filetype_and_ext`, límites de tamaño de archivos) y control de rutas con `realpath`.
- Reglas de negocio contra fraude: límites de pago (sin ahorro voluntario), obligatoriedad de cámara salvo excepciones, sanciones por mora, regla del 70% para refinanciar, verificación de liquidez antes de aprobar créditos.

## Estilos y activos
- `assets/css/lud-style.css` contiene el diseño unificado para tarjetas, formularios, badges y listas. Se encola en frontend y admin con las funciones `lud_enqueue_assets` y `lud_admin_enqueue_assets`.
- Contiene estilos mejorados para selects y checkboxes modernos con `!important` (evita que Elementor/tema los sobreescriba), y banners compactos de estado con tipografía reducida y mensajes menos invasivos en pagos o retiros voluntarios.

## Endpoints y hooks clave
- **Activación:** `register_activation_hook` ejecuta `LUD_DB_Installer::install` y `lud_create_roles`.
- **Shortcodes:** registrados en constructores de `LUD_Module_Transacciones`, `LUD_Module_Creditos` y `LUD_Frontend_Shortcodes`.
- **Form actions (admin-post.php):**
  - Pagos: `lud_procesar_pago`, `lud_aprobar_pago`, `lud_rechazar_pago`.
  - Créditos: `lud_solicitar_credito`, `lud_firmar_deudor`, `lud_aprobar_desembolso`.
  - Socios/tesorería: `lud_guardar_perfil`, `lud_procesar_registro`, `lud_aprobar_registro`, `lud_rechazar_registro`, `lud_actualizar_acciones`, `lud_cancelar_cambio_acciones`, `lud_guardar_edicion_socio`, `lud_entregar_secretaria`, `lud_liquidacion_anual`.
  - Seguridad de comprobantes: `lud_ver_comprobante`.

## Recomendaciones de despliegue y operación
- **Ambiente:** PHP 7.4+ y WordPress actualizado. Asegurar extensión GD para firmas en PNG y disponibilidad de TCPDF si se requieren contratos PDF (`wp-content/librerias_compartidas/tcpdf/`).
- **Backups:** respaldar base de datos antes de actualizaciones; las tablas personalizadas son críticas para historial financiero.
- **Permisos de archivos:** verificar que el usuario del servidor web pueda escribir en los subdirectorios de `uploads/fondo_seguro/`.
- **Correo saliente:** requerido para notificar al deudor solidario; configurar SMTP si el hosting no permite `wp_mail` saliente.
- **Seguridad operativa:** restringir accesos de tesorería a IPs confiables mediante reglas de hosting si es posible.

## Cómo extender
- Nuevos conceptos de recaudo: añadir valores en `fondo_recaudos_detalle` y ajustar vistas de tesorería si requieren KPI dedicado.
- Nuevas validaciones de crédito: extender `LUD_Module_Creditos::verificar_sancion_mora` o agregar verificaciones adicionales antes de `wp_die`/`wp_redirect`.
- Integración con pasarelas de pago: reutilizar `procesar_pago` para validar montos y registrar transacción, sustituyendo la subida de comprobantes por webhooks.

## Módulo de importaciones (socios, pagos y créditos)
El módulo `LUD_Module_Importaciones` vive en Tesorería y está diseñado para migrar información histórica con pagos exactos por transacción.

### Archivos y mapeos soportados
1. **Socios actuales (`Datos usuarios.CSV`)**
   - Crea/actualiza usuarios con la cédula como `user_login` y rol `lud_socio`.
   - Inserta o actualiza la ficha en `fondo_cuentas`.
   - Guarda beneficiarios adicionales en `user_meta` (`lud_beneficiarios_detalle`) y el aporte actual en `lud_aporte_actual`.
2. **Pagos históricos (`pagos_historicos.csv`)**
   - Cada fila representa **un pago real** con fecha exacta.
   - Columnas obligatorias: `documento`, `fecha_pago`.
   - Columnas de conceptos (todas aceptan 0): `ahorro`, `cuota_secretaria`, `capital_credito`, `interes_credito`, `interes_mora_credito`, `multa`, `excedente`.
   - Columna opcional: `detalle`.
3. **Créditos históricos (`creditos_historicos.csv`)**
   - Columnas obligatorias: `documento`, `tipo_credito`, `monto_aprobado`, `fecha_inicio`, `fecha_fin`.
   - Columnas opcionales: `tasa_interes`, `estado_credito`, `saldo_actual`.
   - Se genera la tabla de amortización bajo **sistema Alemán** (capital constante + interés sobre saldo).
4. **Créditos vigentes (`*.xlsx`)**
   - Lee metadatos del crédito (monto, tasa, número de cuotas, fechas) y crea un registro en `fondo_creditos`.
   - Genera la tabla de amortización en `fondo_amortizacion` usando capital, interés, cuota total y abonos pagados.
   - Permite buscar al socio por cédula o por un fragmento de nombre si el archivo está identificado solo por nombre.

### Supuestos operativos y coherencia estatutaria
- Se importan movimientos como **aprobados** para conservar el histórico.
- Se respeta el límite de 10 acciones por socio porque la ficha del socio conserva `numero_acciones` y el motor de pagos aplica la regla estatutaria.
- Los importes de ahorro, intereses y multas respetan los conceptos definidos en estatutos (Art. 7 y Art. 16).
- El detalle de beneficiarios múltiples se almacena en `user_meta` y se visualiza en Tesorería y en la zona de socio.

### Recomendaciones de uso
1. Importar **socios** antes de cualquier movimiento.
2. Importar **pagos históricos** con fechas exactas para cuadrar la caja real.
3. Importar créditos solo cuando el socio exista y tenga su cédula correcta.
4. Para XLSX se requiere la extensión **zip** de PHP activa (usa `ZipArchive`).

## Depuración
- `includes/class-debug-tools.php` expone utilidades adicionales para roles con privilegios altos (p.ej., limpiar data de prueba, revisar tablas). Activar solo en entornos controlados.
- Revisar errores en `wp-content/debug.log` si `WP_DEBUG_LOG` está habilitado.
- La suite de pruebas interna (`LUD_Debug_Tools`) incluye un caso que valida el flujo de retiro voluntario: paz y salvo previo, registro único pendiente y aprobación con motivo.

## Pruebas recomendadas (módulo de retiros)
- **Solicitud exitosa (paz y salvo):** iniciar sesión como socio sin deudas ni créditos, abrir `[lud_retiro_voluntario]`, verificar que muestra el monto estimado y enviar; confirmar que queda en `fondo_retiros` como `pendiente`.
- **Bloqueo por deuda:** simular socio con deuda o crédito activo; abrir el shortcode y validar que se bloquea con mensaje de pago pendiente.
- **Duplicado bloqueado:** con una solicitud `pendiente`, intentar enviar otra y comprobar que se muestra el aviso de solicitud en revisión.
- **Aprobación en Tesorería:** en el dashboard, card “📤 Solicitudes de Retiro”, aprobar y confirmar que el estado cambia a `aprobado` con fecha y usuario que respondió.
- **Rechazo con motivo obligatorio:** rechazar desde la misma card ingresando un motivo; validar que el estado queda `rechazado` y se guarda el texto en `motivo_respuesta`.
- **Persistencia de esquema:** tras actualización, confirmar que la tabla `fondo_retiros` contiene la columna `motivo_respuesta` (ejecutar `DESCRIBE wp_fondo_retiros;` en la BD).

## Glosario rápido de rutas
- Núcleo: `la-union-core.php`
- Lógica de BD: `includes/class-db-installer.php`
- Seguridad de archivos: `includes/class-security.php`
- Pagos frontend: `includes/class-module-transacciones.php`
- Créditos frontend: `includes/class-module-creditos.php`
- Shortcodes de socios: `includes/class-frontend-shortcodes.php`
- Tesorería admin: `includes/class-admin-tesoreria.php`
- Notificaciones y plantillas de correo: `includes/class-notificaciones.php`
- Estilos: `assets/css/lud-style.css`
