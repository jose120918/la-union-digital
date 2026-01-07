<?php
/**
 * Panel administrativo de Tesorería del Fondo La Unión.
 *
 * Gestiona dashboards para tesorería, presidencia y secretaría, además de procesar
 * aprobaciones, rechazos y cálculos automáticos del fondo.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'LUD_Admin_Tesoreria' ) ) {
class LUD_Admin_Tesoreria {

    /**
     * Registra menús y endpoints de administración.
     */
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        
        // Hooks de Procesamiento
        add_action( 'admin_post_lud_aprobar_pago', array( $this, 'procesar_aprobacion' ) );
        add_action( 'admin_post_lud_rechazar_pago', array( $this, 'procesar_rechazo' ) );
        add_action( 'admin_post_lud_aprobar_desembolso', array( $this, 'procesar_desembolso' ) );
        add_action( 'admin_post_lud_liquidacion_anual', array( $this, 'procesar_liquidacion_anual' ) );
        
        // Hooks de Gestión (Nuevos)
        add_action( 'admin_post_lud_actualizar_acciones', array( $this, 'procesar_actualizacion_acciones' ) );
        add_action( 'admin_post_lud_aprobar_registro', array( $this, 'procesar_aprobacion_registro' ) );
        add_action( 'admin_post_lud_rechazar_registro', array( $this, 'procesar_rechazo_registro' ) );
        add_action( 'admin_post_lud_cancelar_cambio_acciones', array( $this, 'procesar_cancelacion_acciones' ) );
        add_action( 'admin_post_lud_guardar_edicion_socio', array( $this, 'procesar_edicion_socio' ) );
        add_action( 'admin_post_lud_entregar_secretaria', array( $this, 'procesar_entrega_secretaria' ) );
        add_action( 'admin_post_lud_responder_retiro', array( $this, 'procesar_respuesta_retiro' ) );
        add_action( 'admin_post_lud_guardar_config_correo', array( $this, 'procesar_config_correos' ) );
        add_action( 'admin_post_lud_enviar_test_correo', array( $this, 'procesar_test_correo' ) );
        add_action( 'admin_post_lud_guardar_asistencia', array( $this, 'procesar_guardado_asistencia' ) );
    }

    /**
     * Crea el menú principal de tesorería visible para los roles con permiso.
     */
    public function register_menu() {
        // Usamos 'lud_view_tesoreria' para que Secretaria, Presidente y Tesorero puedan ver
        add_menu_page( 'Tesorería', '💰 Tesorería', 'lud_view_tesoreria', 'lud-tesoreria', array( $this, 'router_views' ), 'dashicons-money-alt', 2 );
    }

    /**
     * ENRUTADOR DE VISTAS
     */
    /**
     * Enruta la vista solicitada y ejecuta verificaciones automáticas.
     */
    public function router_views() {
        // --- AUTOMATIZACIÓN: CIERRE MENSUAL ---
        // Se ejecuta silenciosamente al cargar el dashboard si detecta que falta cerrar el mes anterior.
        $this->verificar_cierre_automatico();
        $this->ejecutar_cambios_programados();

        $view = isset($_GET['view']) ? $_GET['view'] : 'dashboard';
        $es_presidente = $this->usuario_es_presidencia();
        
        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline" style="margin-bottom:20px;">Gestión de Tesorería La Unión</h1>';
        
        $active_dash = ($view == 'dashboard') ? 'nav-tab-active' : '';
        $active_socio = ($view == 'buscar_socio' || $view == 'detalle_socio') ? 'nav-tab-active' : '';
        $active_hist = ($view == 'historial_intereses') ? 'nav-tab-active' : '';
        $active_hist_anual = ($view == 'historial_anual') ? 'nav-tab-active' : '';
        $active_config = ($view == 'configuracion_fondo') ? 'nav-tab-active' : '';
        $active_asistencia = ($view == 'control_asistencia') ? 'nav-tab-active' : '';
        $active_presidencia = ($view == 'presidencia') ? 'nav-tab-active' : '';
        $active_importaciones = ($view == 'importaciones') ? 'nav-tab-active' : '';
        
        echo '<nav class="nav-tab-wrapper">';
        echo '<a href="?page=lud-tesoreria&view=dashboard" class="nav-tab '.$active_dash.'" title="Ayuda: Aquí ves el dinero total en caja, apruebas pagos y desembolsas créditos.">📊 Tablero Principal</a>';
        echo '<a href="?page=lud-tesoreria&view=buscar_socio" class="nav-tab '.$active_socio.'" title="Ayuda: Aquí buscas a un socio para ver su historia completa.">👥 Directorio y Consultas</a>';
        echo '<a href="?page=lud-tesoreria&view=historial_intereses" class="nav-tab '.$active_hist.'" title="Ayuda: Lista de los dineros entregados en efectivo cada Diciembre.">📜 Historial Intereses Pagados</a>';
        echo '<a href="?page=lud-tesoreria&view=historial_anual" class="nav-tab '.$active_hist_anual.'" title="Resumen anual de ahorro, créditos e intereses.">🧮 Históricos Anuales</a>';
        if ( current_user_can( 'lud_manage_tesoreria' ) ) {
            echo '<a href="?page=lud-tesoreria&view=control_asistencia" class="nav-tab '.$active_asistencia.'" title="Marcar asistencia a asambleas y generar multas automáticas.">🗓️ Control de Asistencia</a>';
        }
        if ( $es_presidente ) {
            echo '<a href="?page=lud-tesoreria&view=presidencia" class="nav-tab '.$active_presidencia.'" title="Aprobar o rechazar solicitudes de ingreso con historial.">🏛️ Presidencia</a>';
        }
        if ( current_user_can( 'manage_options' ) ) {
            echo '<a href="?page=lud-tesoreria&view=configuracion_fondo" class="nav-tab '.$active_config.'" title="Configura plantillas de correo y pruebas SMTP.">⚙️ Configuración del Fondo</a>';
        }
        if ( current_user_can( 'lud_manage_tesoreria' ) ) {
            echo '<a href="?page=lud-tesoreria&view=importaciones" class="nav-tab '.$active_importaciones.'" title="Carga masiva de socios, aportes y créditos históricos.">📥 Importaciones</a>';
        }
        echo '</nav>';
        echo '<br>';

        if ( $view == 'dashboard' ) {
            $this->render_dashboard_general();
        } elseif ( $view == 'buscar_socio' ) {
            $this->render_buscador_socios();
        } elseif ( $view == 'detalle_socio' ) {
            $this->render_hoja_vida_socio();
        } elseif ( $view == 'historial_intereses' ) {
            $this->render_historial_intereses();
        } elseif ( $view == 'historial_anual' ) {
            $this->render_historial_anual();
        } elseif ( $view == 'editar_socio' ) {
            $this->render_editor_socio();
        } elseif ( $view == 'presidencia' ) {
            $this->render_panel_presidencia();
        } elseif ( $view == 'control_asistencia' ) {
            $this->render_control_asistencia();
        } elseif ( $view == 'configuracion_fondo' && current_user_can( 'manage_options' ) ) {
            $this->render_configuracion_fondo();
        } elseif ( $view == 'importaciones' && current_user_can( 'lud_manage_tesoreria' ) ) {
            LUD_Module_Importaciones::render_vista_importaciones();
        }
        echo '</div>';
    }

    /**
     * Revisa si hay cambios de acciones programados que ya deban aplicarse hoy.
     * Se ejecuta al cargar la tesorería.
     */
    /**
     * Aplica automáticamente los cambios de acciones programados para la fecha actual.
     */
    public function ejecutar_cambios_programados() {
        // Buscamos usuarios con cambios pendientes
        $usuarios = get_users(array('meta_key' => 'lud_acciones_programadas'));
        $hoy = date('Y-m-d');

        foreach ($usuarios as $user) {
            $programado = get_user_meta($user->ID, 'lud_acciones_programadas', true);
            
            // Si existe programación y la fecha de hoy es igual o mayor a la fecha efectiva
            if ( $programado && $hoy >= $programado['fecha_efectiva'] ) {
                global $wpdb;
                
                // 1. Obtener valor anterior para el log
                $anteriores = $wpdb->get_var("SELECT numero_acciones FROM {$wpdb->prefix}fondo_cuentas WHERE user_id = {$user->ID}");
                $cantidad_aplicar = max( 0, min( 10, intval( $programado['cantidad'] ) ) ); // Comentario: respetar límite estatutario de 10.
                
                // 2. Aplicar el cambio en la Tabla Maestra
                $wpdb->update(
                    "{$wpdb->prefix}fondo_cuentas",
                    ['numero_acciones' => $cantidad_aplicar],
                    ['user_id' => $user->ID]
                );

                // 3. Registrar en Historial (Log de Auditoría)
                $detalle = "SISTEMA: Cambio de acciones efectivo ($anteriores -> {$cantidad_aplicar}). Motivo: " . $programado['motivo'];
                $wpdb->insert("{$wpdb->prefix}fondo_transacciones", [
                    'user_id' => $user->ID, 'tipo' => 'aporte', 'monto' => 0, 'estado' => 'aprobado',
                    'detalle' => $detalle, 'aprobado_por' => get_current_user_id(), 'fecha_registro' => current_time('mysql')
                ]);

                // 4. Borrar la programación ya que fue aplicada
                delete_user_meta($user->ID, 'lud_acciones_programadas');
            }
        }
    }

    /**
     * Determina si el usuario actual tiene rol de presidencia o es administrador.
     */
    private function usuario_es_presidencia() {
        $usuario = wp_get_current_user();
        $roles = (array) $usuario->roles;
        return in_array( 'lud_presidente', $roles ) || current_user_can( 'manage_options' );
    }

    // --- VISTA 1: TABLERO GENERAL ---
    /**
     * Dibuja el tablero principal con KPIs y paneles operativos.
     */
    private function render_dashboard_general() {
        global $wpdb;
        $anio_actual = date('Y');
        
        // SEGURIDAD: Definir si el usuario puede editar (Tesorero/Admin/Presidente)
        $puede_editar = current_user_can('lud_manage_tesoreria');

        // 1. CÁLCULOS DE CAJA (Dinero Físico Real)
        // Entradas (solo año actual para no sumar históricos de años cerrados).
        $total_entradas = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(monto) FROM {$wpdb->prefix}fondo_recaudos_detalle WHERE YEAR(fecha_recaudo) = %d",
            $anio_actual
        ));
        // Salidas Operativas (solo año actual).
        $total_gastos = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(monto) FROM {$wpdb->prefix}fondo_gastos WHERE YEAR(fecha_gasto) = %d",
            $anio_actual
        ));
        // Salidas por Créditos (saldo vigente real).
        $total_prestado = $wpdb->get_var("SELECT SUM(saldo_actual) FROM {$wpdb->prefix}fondo_creditos WHERE estado IN ('activo', 'mora')");
        // Salidas por Pago de Intereses (Liquidación Diciembre del año actual)
        // Al liquidar, el dinero sale de la caja para entregarse al socio. Debemos restarlo.
        $total_intereses_pagados = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(utilidad_asignada) FROM {$wpdb->prefix}fondo_utilidades_mensuales WHERE estado = 'liquidado' AND anio = %d",
            $anio_actual
        ));

        $dinero_fisico = floatval($total_entradas) - floatval($total_gastos) - floatval($total_prestado) - floatval($total_intereses_pagados);

        $recaudo_sec = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(monto) FROM {$wpdb->prefix}fondo_recaudos_detalle WHERE concepto = 'cuota_secretaria' AND YEAR(fecha_recaudo) = %d",
            $anio_actual
        ));
        $gasto_sec = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(monto) FROM {$wpdb->prefix}fondo_gastos WHERE categoria = 'secretaria' AND YEAR(fecha_gasto) = %d",
            $anio_actual
        ));
        $fondo_secretaria = floatval($recaudo_sec) - floatval($gasto_sec);
        
        $disponible_para_creditos = $dinero_fisico - $fondo_secretaria;

        // --- INICIO: NUEVOS CÁLCULOS KPI ---
        $mes_actual = date('m');
        $anio_actual = date('Y');

        // 1. Intereses Totales Año en Curso (Rentabilidad)
        // Sumamos interés corriente y la nueva mora del 4% [cite: 181]
        $intereses_ytd = $wpdb->get_var("
            SELECT SUM(monto) FROM {$wpdb->prefix}fondo_recaudos_detalle 
            WHERE concepto IN ('interes_credito', 'interes_mora_credito') 
            AND YEAR(fecha_recaudo) = $anio_actual
        ");

        // 2. Multas Año en Curso
        $multas_ytd = $wpdb->get_var("
            SELECT SUM(monto) FROM {$wpdb->prefix}fondo_recaudos_detalle 
            WHERE concepto = 'multa' 
            AND YEAR(fecha_recaudo) = $anio_actual
        ");

        // 3. Caja Secretaría (Solo Mes Actual)
        // Lo que entró por concepto 'cuota_secretaria' este mes
        $recaudo_sec_mes = $wpdb->get_var("
            SELECT SUM(monto) FROM {$wpdb->prefix}fondo_recaudos_detalle 
            WHERE concepto = 'cuota_secretaria' 
            AND MONTH(fecha_recaudo) = $mes_actual AND YEAR(fecha_recaudo) = $anio_actual
        ");
        // Lo que ya se le entregó (Gastó) a la secretaria este mes
        $pagado_sec_mes = $wpdb->get_var("
            SELECT SUM(monto) FROM {$wpdb->prefix}fondo_gastos 
            WHERE categoria = 'secretaria' 
            AND MONTH(fecha_gasto) = $mes_actual AND YEAR(fecha_gasto) = $anio_actual
        ");
        $pendiente_entrega_sec = floatval($recaudo_sec_mes) - floatval($pagado_sec_mes);

        // 4. Proyección de Meta (Faltante por Recaudar)
        // Calculamos cuántas acciones activas hay para saber cuánto debería entrar fijo
        $total_acciones_activas = $wpdb->get_var("SELECT SUM(numero_acciones) FROM {$wpdb->prefix}fondo_cuentas WHERE estado_socio = 'activo'");
        $meta_aporte_ideal = $total_acciones_activas * 51000; // 50k Ahorro + 1k Sec
        
        // Cuánto ha entrado realmente por Ahorro + Secretaría este mes
        $recaudo_real_aporte = $wpdb->get_var("
            SELECT SUM(monto) FROM {$wpdb->prefix}fondo_recaudos_detalle 
            WHERE concepto IN ('ahorro', 'cuota_secretaria') 
            AND MONTH(fecha_recaudo) = $mes_actual AND YEAR(fecha_recaudo) = $anio_actual
        ");
        
        $faltante_meta = $meta_aporte_ideal - floatval($recaudo_real_aporte);
        // Si ya superamos la meta (ej. pagos adelantados), mostramos 0, sino el negativo.
        $indicador_meta = ($faltante_meta > 0) ? ($faltante_meta * -1) : 0; 
        $porcentaje_cumplimiento = ($meta_aporte_ideal > 0) ? round(($recaudo_real_aporte / $meta_aporte_ideal) * 100, 1) : 0;

        // 5. Radar de Morosos (Lógica combinada: Aportes viejos OR Créditos en Mora)
        $fecha_corte_mora = date('Y-m-01', strtotime('-1 month')); // Si su ultimo aporte es anterior al mes pasado
        $morosos = $wpdb->get_results("
            SELECT u.display_name, c.fecha_ultimo_aporte, cr.saldo_actual 
            FROM {$wpdb->prefix}fondo_cuentas c
            JOIN {$wpdb->users} u ON c.user_id = u.ID
            LEFT JOIN {$wpdb->prefix}fondo_creditos cr ON c.user_id = cr.user_id AND cr.estado = 'mora'
            WHERE (c.fecha_ultimo_aporte < '$fecha_corte_mora' AND c.estado_socio = 'activo')
               OR (cr.id IS NOT NULL)
            GROUP BY c.user_id
            ORDER BY u.display_name ASC
        ");

        // 6. Métricas de cartera y operación para Presidencia/Secretaría
        // Nota: Separamos las métricas para mostrar claridad en el tablero.
        $cartera_vigente = floatval( $wpdb->get_var("SELECT SUM(saldo_actual) FROM {$wpdb->prefix}fondo_creditos WHERE estado IN ('activo','mora')") );
        $cartera_mora = floatval( $wpdb->get_var("SELECT SUM(saldo_actual) FROM {$wpdb->prefix}fondo_creditos WHERE estado = 'mora'") );
        $porcentaje_mora = ($cartera_vigente > 0) ? round( ($cartera_mora / $cartera_vigente) * 100, 1 ) : 0;

        $recaudo_mes_total = floatval( $wpdb->get_var("SELECT SUM(monto) FROM {$wpdb->prefix}fondo_recaudos_detalle WHERE MONTH(fecha_recaudo) = $mes_actual AND YEAR(fecha_recaudo) = $anio_actual") );
        $gasto_mes_total = floatval( $wpdb->get_var("SELECT SUM(monto) FROM {$wpdb->prefix}fondo_gastos WHERE MONTH(fecha_gasto) = $mes_actual AND YEAR(fecha_gasto) = $anio_actual") );

        $creditos_pendientes_monto = floatval( $wpdb->get_var("SELECT SUM(monto_solicitado) FROM {$wpdb->prefix}fondo_creditos WHERE estado = 'pendiente_tesoreria'") );
        $creditos_pendientes_total = intval( $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fondo_creditos WHERE estado = 'pendiente_tesoreria'") );

        $socios_activos = intval( $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fondo_cuentas WHERE estado_socio = 'activo'") );
        $socios_morosos = count($morosos);

        // Histórico entregas secretaría agrupado por mes-año (últimos 6 meses)
        $historico_secretaria = $wpdb->get_results("
            SELECT DATE_FORMAT(fecha_gasto, '%Y-%m') as periodo, SUM(monto) as total
            FROM {$wpdb->prefix}fondo_gastos
            WHERE categoria = 'secretaria'
            GROUP BY periodo
            ORDER BY periodo DESC
            LIMIT 6
        ");

        // Solicitudes de retiro (pendientes para decisión)
        $retiros_pendientes = $wpdb->get_results(
            "SELECT r.*, u.display_name, u.user_email 
             FROM {$wpdb->prefix}fondo_retiros r
             JOIN {$wpdb->users} u ON r.user_id = u.ID
             WHERE r.estado = 'pendiente'
             ORDER BY r.fecha_solicitud ASC"
        );

        // 2. CONSULTAS PENDIENTES
        $pendientes = $wpdb->get_results( 
            "SELECT tx.*, u.user_email, u.display_name 
             FROM {$wpdb->prefix}fondo_transacciones tx
             JOIN {$wpdb->users} u ON tx.user_id = u.ID
             WHERE tx.estado = 'pendiente' ORDER BY tx.fecha_registro ASC"
        );
        
        $creditos_pendientes = $wpdb->get_results(
            "SELECT c.*, u.display_name FROM {$wpdb->prefix}fondo_creditos c
             JOIN {$wpdb->users} u ON c.user_id = u.ID
             WHERE c.estado = 'pendiente_tesoreria'"
        );
        
        ?>
        
        <?php if ( isset( $_GET['lud_msg'] ) ): ?>
            <?php if ( $_GET['lud_msg'] === 'retiro_aprobado' ): ?>
                <div class="notice notice-success"><p>✅ Retiro aprobado y agendado para entrega en reunión.</p></div>
            <?php elseif ( $_GET['lud_msg'] === 'retiro_rechazado' ): ?>
                <div class="notice notice-error"><p>❌ Solicitud de retiro rechazada y notificada internamente.</p></div>
            <?php endif; ?>
        <?php endif; ?>
        
        <div style="display:flex; gap:20px; flex-wrap:wrap; margin-bottom:30px;">
            <div class="lud-card" style="flex:1; min-width:300px; background:#2c3e50; color:#fff; cursor:help;" 
                 title="AYUDA: Esta es la suma de todo el dinero que debería haber físicamente en la Caja Fuerte. Si el conteo físico dice otra cosa, hay un descuadre.">
                <h3 style="color:#bdc3c7; margin-top:0;">🏦 Dinero Total en Caja</h3>
                <div style="font-size:2.5rem; font-weight:bold; color:#111;">$ <?php echo number_format($dinero_fisico, 0, ',', '.'); ?></div>
                <p style="opacity:0.8;">Arqueo físico total (Incluye Secretaría).</p>
            </div>
            
            <div class="lud-card" style="flex:1; min-width:300px; background:#27ae60; color:#fff; cursor:help;"
                 title="AYUDA: Este es el dinero que REALMENTE puedes prestar. El sistema ya restó automáticamente la plata que es de la Secretaría, para que no la toques.">
                <h3 style="color:#a9dfbf; margin-top:0;">✅ Disponible para Prestar</h3>
                <div style="font-size:2.5rem; font-weight:bold; color:#111;">$ <?php echo number_format($disponible_para_creditos, 0, ',', '.'); ?></div>
                <p style="opacity:0.8;">(Descontando $<?php echo number_format($fondo_secretaria); ?> de Secretaría)</p>
            </div>
        </div>

        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:20px; margin-bottom:30px;">
            
            <div class="lud-card" style="border-bottom: 4px solid #2980b9;" title="Ayuda: Suma de intereses corrientes y mora cobrados en el año. Útil para Presidencia.">
                <h4 style="margin:0; color:#7f8c8d;">📈 Intereses Ganados (Año)</h4>
                <div style="font-size:1.8rem; font-weight:bold; color:#111;">$ <?php echo number_format(floatval($intereses_ytd), 0, ',', '.'); ?></div>
                <small>Rentabilidad bruta del fondo.</small>
                </div>

            <div class="lud-card" style="border-bottom: 4px solid #c0392b;" title="Ayuda: Multas cobradas a socios por retrasos. Útil para Secretaría.">
                <h4 style="margin:0; color:#7f8c8d;">⚖️ Multas (Año)</h4>
                <div style="font-size:1.8rem; font-weight:bold; color:#c0392b;">$ <?php echo number_format(floatval($multas_ytd), 0, ',', '.'); ?></div>
                <small>Sanciones aplicadas.</small>
            </div>

            <?php $color_meta = ($indicador_meta < 0) ? '#c0392b' : '#111'; ?>
            <div class="lud-card" style="border-bottom: 4px solid <?php echo ($indicador_meta == 0) ? '#27ae60' : '#f39c12'; ?>;" title="Ayuda: Diferencia entre lo que debía recaudar el fondo este mes y lo recibido.">
                <h4 style="margin:0; color:#7f8c8d;">🎯 Meta Mensual</h4>
                <div style="font-size:1.8rem; font-weight:bold; color:<?php echo $color_meta; ?>;">
                    $ <?php echo number_format($indicador_meta, 0, ',', '.'); ?>
                </div>
                <small><?php echo ($indicador_meta == 0) ? '✅ Meta Cumplida' : 'Falta recaudar en aportes'; ?></small>
            </div>

            <div class="lud-card" style="background:#fff3e0; border:1px solid #ffe0b2;" title="Ayuda: Solo muestra lo recaudado para Secretaría en el mes en curso.">
                <h4 style="margin:0; color:#e65100;">📂 Caja Secretaría (Recaudo Mes)</h4>
                <div style="font-size:1.5rem; font-weight:bold; color:#ef6c00;">$ <?php echo number_format($recaudo_sec_mes, 0, ',', '.'); ?></div>
                <small>Corresponde a las cuotas de secretaría registradas este mes.</small>
            </div>
        </div>

        <div class="lud-card" style="border-left:4px solid #546e7a; margin-bottom:30px;" title="Histórico de entregas efectuadas a Secretaría por mes.">
            <h3 style="margin-top:0;">📑 Histórico Entregas Secretaría</h3>
            <?php if ( empty( $historico_secretaria ) ): ?>
                <p style="color:#666;">Sin registros de entregas.</p>
            <?php else: ?>
                <table class="widefat striped">
                    <thead><tr><th>Mes</th><th>Total entregado</th></tr></thead>
                    <tbody>
                    <?php foreach ( $historico_secretaria as $fila ): ?>
                        <tr>
                            <td><?php echo esc_html( $fila->periodo ); ?></td>
                            <td style="font-weight:bold; color:#2c3e50;">$ <?php echo number_format( $fila->total, 0, ',', '.' ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:20px; margin-bottom:30px;">
            <div class="lud-card" style="border-left: 4px solid #6c5ce7;" title="Ayuda: Saldo vivo de todos los créditos activos.">
                <h4 style="margin:0; color:#7f8c8d;">💼 Cartera Vigente</h4>
                <div style="font-size:1.8rem; font-weight:bold; color:#111;">$ <?php echo number_format($cartera_vigente, 0, ',', '.'); ?></div>
                <small>Saldo total de créditos activos y en seguimiento.</small>
            </div>

            <div class="lud-card" style="border-left: 4px solid #d63031;" title="Ayuda: Valor de cartera que está en mora; indicador clave para Presidencia.">
                <h4 style="margin:0; color:#7f8c8d;">🚨 Cartera en Mora</h4>
                <div style="font-size:1.8rem; font-weight:bold; color:#d63031;">$ <?php echo number_format($cartera_mora, 0, ',', '.'); ?></div>
                <small><?php echo $porcentaje_mora; ?>% de la cartera vigente.</small>
            </div>

            <div class="lud-card" style="border-left: 4px solid #16a085;" title="Ayuda: Todo lo recibido este mes (ahorros, créditos y multas).">
                <h4 style="margin:0; color:#7f8c8d;">📊 Recaudo del Mes</h4>
                <div style="font-size:1.8rem; font-weight:bold; color:#111;">$ <?php echo number_format($recaudo_mes_total, 0, ',', '.'); ?></div>
                <small>Incluye ahorro, créditos y multas.</small>
            </div>

            <div class="lud-card" style="border-left: 4px solid #e67e22;" title="Ayuda: Suma de pagos operativos hechos en el mes.">
                <h4 style="margin:0; color:#7f8c8d;">💸 Gasto Operativo del Mes</h4>
                <div style="font-size:1.8rem; font-weight:bold; color:#111;">$ <?php echo number_format($gasto_mes_total, 0, ',', '.'); ?></div>
                <small>Pagos ya egresados del fondo.</small>
            </div>

            <div class="lud-card" style="border-left: 4px solid #009688;" title="Ayuda: Porcentaje de cumplimiento de aportes obligatorios en el mes.">
                <h4 style="margin:0; color:#7f8c8d;">🎯 Cumplimiento Aportes</h4>
                <div style="font-size:1.8rem; font-weight:bold; color:#111;"><?php echo $porcentaje_cumplimiento; ?>%</div>
                <small><?php echo ($porcentaje_cumplimiento >= 100) ? 'Meta alcanzada' : 'Seguimos recolectando.'; ?></small>
            </div>

            <div class="lud-card" style="border-left: 4px solid #27ae60;" title="Ayuda: Solicitudes listas para desembolsar; prioriza según saldo disponible.">
                <h4 style="margin:0; color:#7f8c8d;">🧮 Créditos en Cola</h4>
                <div style="font-size:1.8rem; font-weight:bold; color:#111;"><?php echo $creditos_pendientes_total; ?> solicitudes</div>
                <small>Por desembolsar: $ <?php echo number_format($creditos_pendientes_monto, 0, ',', '.'); ?></small>
            </div>

            <div class="lud-card" style="border-left: 4px solid #2d3436;" title="Ayuda: Total de socios activos y lista breve de alertas por mora.">
                <h4 style="margin:0; color:#7f8c8d;">👥 Socios Activos</h4>
                <div style="font-size:1.8rem; font-weight:bold; color:#2d3436;"><?php echo $socios_activos; ?></div>
                <?php 
                // Comentario: Se listan los nombres con alerta para transparencia.
                $nombres_morosos = array_map( function( $m ) { return $m->display_name; }, $morosos );
                $texto_morosos = (!empty($nombres_morosos)) ? implode(', ', $nombres_morosos) : 'Sin alertas en cartera';
                ?>
                <small><?php echo $socios_morosos; ?> con alerta en cartera: <?php echo esc_html($texto_morosos); ?></small>
            </div>
        </div>

        <?php if ( !empty($morosos) ): ?>
        <div class="lud-card" style="border-left: 5px solid #c0392b; margin-bottom:30px; background:#fff5f5;">
            <h3 style="color:#c0392b; margin-top:0;">⚠️ Alerta de Cartera (Morosos)</h3>
            <p>Los siguientes socios tienen retrasos superiores a 1 mes o créditos en mora:</p>
            <ul style="list-style:disc; margin-left:20px;">
                <?php foreach($morosos as $m): 
                    $es_mora_credito = ($m->saldo_actual > 0);
                    $detalle = $es_mora_credito ? "Crédito en Mora" : "Aporte atrasado (último: ".date('d/M/Y', strtotime($m->fecha_ultimo_aporte)).")";
                ?>
                    <li>
                        <strong><?php echo $m->display_name; ?>:</strong> 
                        <span style="color:#c0392b;"><?php echo $detalle; ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?> <?php if ( $puede_editar ): ?>
        
            <?php if(date('m') == '12'): ?>
            <div class="lud-card" style="border-left: 5px solid #e67e22; margin-bottom:30px;">
                <h3 title="AYUDA: Herramientas para fin de año.">🎄 Cierre de Año (Diciembre)</h3>
                <p>El sistema ya calculó automáticamente los rendimientos mes a mes. Ahora debes liquidarlos para entregar el efectivo.</p>
                <div style="display:flex; gap:10px;">
                    <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>" onsubmit="return confirm('¿ESTÁS SEGURA? \n\nAl confirmar, el sistema marcará los intereses de este año como PAGADOS. \n\nEsto significa que debes tener el dinero en efectivo listo para entregárselo a los socios.');">
                        <input type="hidden" name="action" value="lud_liquidacion_anual">
                        <button class="button button-primary button-large" title="IMPORTANTE: Clic aquí solo cuando vayas a entregar el dinero de intereses a los socios.">
                            🎁 Liquidar y Entregar Intereses (Efectivo)
                        </button>
                    </form>
                </div>
            </div>
            <?php else: ?>
                <div class="lud-card" style="background:#f0f4c3; border-left: 5px solid #cddc39; margin-bottom:30px;">
                    <strong>ℹ️ Sistema Automático:</strong> El cálculo de rendimientos mensuales se realiza automáticamente. No necesitas hacer nada hasta Diciembre.
                </div>
            <?php endif; ?>

        <?php endif; ?>

        <?php 
        $nuevos_socios = $wpdb->get_results("SELECT u.display_name, c.* FROM {$wpdb->prefix}fondo_cuentas c JOIN {$wpdb->users} u ON c.user_id = u.ID WHERE c.estado_socio = 'pendiente'");
        if ( ! empty($nuevos_socios) && $puede_editar ): 
        ?>
        <div class="lud-card" style="border-left: 5px solid #2980b9; background:#f4faff;">
            <h3>👤 Solicitudes de Ingreso Pendientes</h3>
            <table class="widefat striped">
                <thead><tr><th>Nombre</th><th>Documento</th><th>Teléfono</th><th>Aporte Inicial</th><th>Acciones</th></tr></thead>
                <tbody>
                <?php foreach($nuevos_socios as $ns): ?>
                    <tr>
                        <td>
                            <strong><?php echo $ns->display_name; ?></strong><br>
                            <small><?php echo $ns->email_contacto; ?></small>
                        </td>
                        <td><?php echo $ns->tipo_documento . ' ' . $ns->numero_documento; ?></td>
                        <td><?php echo $ns->telefono_contacto; ?></td>
                        <td>$ <?php echo number_format($ns->aporte_inicial); ?></td>
                        <td>
                            <div style="display:flex; gap:5px;">
                                <a href="<?php echo admin_url('admin-post.php?action=lud_ver_comprobante&file=documentos/'.$ns->url_documento_id); ?>" target="_blank" class="button button-small" title="Ver PDF Documento">📄 PDF</a>
                                <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>">
                                    <input type="hidden" name="action" value="lud_aprobar_registro">
                                    <input type="hidden" name="cuenta_id" value="<?php echo $ns->id; ?>">
                                    <?php wp_nonce_field('aprobar_socio_'.$ns->id, 'security'); ?>
                                    <button class="button button-primary button-small">Aprobar</button>
                                </form>
                                <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>" onsubmit="return confirm('¿Rechazar solicitud?');" style="display:flex; flex-direction:column; gap:4px;">
                                    <input type="hidden" name="action" value="lud_rechazar_registro">
                                    <input type="hidden" name="cuenta_id" value="<?php echo $ns->id; ?>">
                                    <?php wp_nonce_field('rechazar_socio_'.$ns->id, 'security'); ?>
                                    <input type="text" name="motivo_rechazo" class="lud-input" placeholder="Motivo de rechazo" required>
                                    <button class="button button-link-delete button-small">Rechazar</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
            
            <div class="lud-card">
                <h3 title="AYUDA: Aquí aparecen los socios que dicen haber entregado dinero. Debes verificar el efectivo en la caja antes de aprobar.">📥 Pagos por Aprobar</h3>
                <?php if ( empty($pendientes) ): ?>
                    <p style="color:#27ae60;">✅ Todo al día. No hay pagos pendientes.</p>
                <?php else: ?>
                    <table class="widefat striped">
                        <?php foreach($pendientes as $tx): ?>
                        <tr>
                            <td>
                                <strong><?php echo $tx->display_name; ?></strong><br>
                                <span style="color:#2980b9; font-weight:bold; font-size:1.1em;">$ <?php echo number_format($tx->monto); ?></span><br>
                                <small><?php echo date_i18n('d/M', strtotime($tx->fecha_registro)); ?></small>
                            </td>
                            <td style="text-align:right;">
                                <?php echo $this->construir_enlaces_comprobantes( $tx->comprobante_url, 'Ver archivo' ); ?>
                                
                                <div style="margin-top:5px;">
                                    <?php if ( $puede_editar ): ?>
                                        <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>" style="display:inline;">
                                            <input type="hidden" name="action" value="lud_aprobar_pago">
                                            <input type="hidden" name="tx_id" value="<?php echo $tx->id; ?>">
                                            <?php wp_nonce_field('aprobar_'.$tx->id, 'security'); ?>
                                            <button class="button button-primary" title="AYUDA: Clic aquí si YA RECIBISTE el dinero en efectivo. Esto suma el saldo al socio.">Aprobar</button>
                                        </form>
                                        <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>" style="display:inline;" onsubmit="return confirm('¿Rechazar pago?');">
                                            <input type="hidden" name="action" value="lud_rechazar_pago">
                                            <input type="hidden" name="tx_id" value="<?php echo $tx->id; ?>">
                                            <input type="hidden" name="motivo" value="No validado">
                                            <?php wp_nonce_field('rechazar_'.$tx->id, 'security'); ?>
                                            <button class="button button-link-delete" style="color:#c0392b;" title="AYUDA: Clic aquí si el dinero no llegó a la caja.">Rechazar</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="dashicons dashicons-lock" title="No tienes permiso para aprobar."></span> <small>Solo Lectura</small>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
            </div>

            <div class="lud-card">
                <h3 title="AYUDA: Aquí aparecen los créditos que ya fueron firmados por Socio y Fiador. Solo falta que entregues el dinero de la caja.">💸 Créditos para Desembolsar</h3>
                <?php if ( empty($creditos_pendientes) ): ?>
                    <p style="color:#7f8c8d;">No hay solicitudes pendientes.</p>
                <?php else: ?>
                    <?php foreach($creditos_pendientes as $c): ?>
                    <div style="border:1px solid #ddd; padding:10px; margin-bottom:10px; border-radius:5px;">
                        <strong><?php echo $c->display_name; ?></strong> solicita <strong style="color:#c0392b;">$<?php echo number_format($c->monto_solicitado); ?></strong>
                        <p style="font-size:0.9em; margin:5px 0;">Plazo: <?php echo $c->plazo_meses; ?> meses | Firmas: ✅ Completas</p>
                        
                        <?php if ( $puede_editar ): ?>
                            <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>" onsubmit="return confirm('¿Confirmas el desembolso?');">
                                <input type="hidden" name="action" value="lud_aprobar_desembolso">
                                <input type="hidden" name="credito_id" value="<?php echo $c->id; ?>">
                                <input type="text" name="datos_entrega" placeholder="Ref. de entrega / Firma" required style="width:100%; margin-bottom:5px;" title="Escribe aquí algún detalle de la entrega del efectivo.">
                                <?php wp_nonce_field('lud_approve_credit', 'security'); ?>
                                <button class="button button-primary" style="width:100%;" title="AYUDA: Dale clic SOLO cuando ya le hayas entregado el dinero físico al socio.">Confirmar Desembolso</button>
                            </form>
                        <?php else: ?>
                            <div style="color:#777; font-style:italic; background:#f0f0f0; padding:5px; text-align:center;">⚠️ Esperando aprobación de Tesorería</div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="lud-card" style="border-left:5px solid #8e44ad; margin-bottom:30px;" title="Ayuda: Aprobar o rechazar retiros voluntarios solicitados por los socios.">
            <h3>📤 Solicitudes de Retiro</h3>
            <?php if ( empty( $retiros_pendientes ) ): ?>
                <p style="color:#27ae60;">✅ No hay retiros pendientes de decisión.</p>
            <?php else: ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Socio</th>
                            <th>Monto estimado</th>
                            <th>Motivo socio</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $retiros_pendientes as $r ): ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html( $r->display_name ); ?></strong><br>
                                <small>Solicitado: <?php echo date_i18n( 'd/M H:i', strtotime( $r->fecha_solicitud ) ); ?></small>
                            </td>
                            <td style="color:#2c3e50; font-weight:bold;">
                                $ <?php echo number_format( $r->monto_estimado, 0, ',', '.' ); ?>
                            </td>
                            <td style="max-width:260px;"><?php echo nl2br( esc_html( $r->detalle ) ); ?></td>
                            <td>
                                <?php if ( $puede_editar ): ?>
                                    <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>" style="margin-bottom:8px;">
                                        <input type="hidden" name="action" value="lud_responder_retiro">
                                        <input type="hidden" name="retiro_id" value="<?php echo intval( $r->id ); ?>">
                                        <input type="hidden" name="decision" value="aprobado">
                                        <?php wp_nonce_field( 'lud_responder_retiro_' . $r->id, 'security' ); ?>
                                        <button class="button button-primary" style="width:100%;" title="Aprueba el retiro para entregarlo en la próxima reunión con liquidez.">Aprobar</button>
                                    </form>
                                    <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>">
                                        <input type="hidden" name="action" value="lud_responder_retiro">
                                        <input type="hidden" name="retiro_id" value="<?php echo intval( $r->id ); ?>">
                                        <input type="hidden" name="decision" value="rechazado">
                                        <textarea name="motivo" rows="2" style="width:100%; margin-bottom:6px;" placeholder="Motivo de rechazo (obligatorio)" required></textarea>
                                        <?php wp_nonce_field( 'lud_responder_retiro_' . $r->id, 'security' ); ?>
                                        <button class="button button-link-delete" style="width:100%; color:#c0392b;" title="Requiere escribir el motivo para notificar a la asamblea.">Rechazar</button>
                                    </form>
                                <?php else: ?>
                                    <small>Solo lectura.</small>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    // --- VISTA 2: BUSCADOR DE SOCIOS ---
    /**
     * Renderiza el buscador y listado básico de socios para consulta rápida.
     */
    private function render_buscador_socios() {
        global $wpdb;
        $search = isset($_POST['s_socio']) ? sanitize_text_field($_POST['s_socio']) : '';
        
        $socios = [];
        if ( !empty($search) ) {
            $socios = $wpdb->get_results( $wpdb->prepare(
                "SELECT u.ID, u.display_name, u.user_email, c.numero_acciones, c.estado_socio 
                 FROM {$wpdb->users} u 
                 JOIN {$wpdb->prefix}fondo_cuentas c ON u.ID = c.user_id 
                 WHERE u.display_name LIKE %s OR u.user_email LIKE %s",
                '%' . $search . '%', '%' . $search . '%'
            ));
        } else {
            $socios = $wpdb->get_results("SELECT u.ID, u.display_name, u.user_email, c.numero_acciones, c.estado_socio FROM {$wpdb->users} u JOIN {$wpdb->prefix}fondo_cuentas c ON u.ID = c.user_id LIMIT 10");
        }
        ?>
        <div class="lud-card" style="max-width:800px; margin:0 auto;">
            <h3 title="AYUDA: Usa esta pantalla para ver cuánto debe o cuánto tiene ahorrado un socio específico.">🔍 Buscar Asociado</h3>
            <form method="POST">
                <input type="text" name="s_socio" value="<?php echo esc_attr($search); ?>" placeholder="Escribe nombre o correo..." style="width:70%; padding:8px; font-size:16px;">
                <button class="button button-primary button-hero" title="Clic para buscar">Buscar</button>
            </form>
            
            <table class="wp-list-table widefat fixed striped" style="margin-top:20px;">
                <thead><tr><th>Nombre</th><th>Acciones</th><th>Estado</th><th>Acción</th></tr></thead>
                <tbody>
                <?php if(empty($socios)): ?>
                    <tr><td colspan="4">No se encontraron resultados.</td></tr>
                <?php else: ?>
                    <?php foreach($socios as $s): ?>
                    <tr>
                        <td>
                            <strong><?php echo $s->display_name; ?></strong><br>
                            <small><?php echo $s->user_email; ?></small>
                        </td>
                        <td><?php echo $s->numero_acciones; ?></td>
                        <td>
                            <?php 
                            $color = ($s->estado_socio == 'activo') ? '#27ae60' : '#c0392b';
                            echo "<span style='color:$color; font-weight:bold;'>".ucfirst($s->estado_socio)."</span>";
                            ?>
                        </td>
                        <td>
                            <a href="?page=lud-tesoreria&view=detalle_socio&id=<?php echo $s->ID; ?>" class="button button-primary" title="Clic para ver todos los detalles financieros de este socio.">Ver Hoja de Vida</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    // --- VISTA 3: HOJA DE VIDA DETALLADA ---
    /**
     * Muestra la hoja de vida financiera de un socio específico.
     */
    private function render_hoja_vida_socio() {
        if ( !isset($_GET['id']) ) return;
        $user_id = intval($_GET['id']);
        
        global $wpdb;
        $user = get_userdata($user_id);
        $cuenta = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}fondo_cuentas WHERE user_id = $user_id");
        
        $fecha_inicio = isset($_GET['f_inicio']) ? sanitize_text_field($_GET['f_inicio']) : '';
        $fecha_fin = isset($_GET['f_fin']) ? sanitize_text_field($_GET['f_fin']) : '';

        // Comentario: Armamos la cláusula de fechas para filtrar el historial de transacciones.
        $filtro_fechas = "";
        if ( $fecha_inicio && $fecha_fin ) {
            $filtro_fechas = $wpdb->prepare("AND DATE(fecha_registro) BETWEEN %s AND %s", $fecha_inicio, $fecha_fin);
        } elseif ( $fecha_inicio ) {
            $filtro_fechas = $wpdb->prepare("AND DATE(fecha_registro) >= %s", $fecha_inicio);
        } elseif ( $fecha_fin ) {
            $filtro_fechas = $wpdb->prepare("AND DATE(fecha_registro) <= %s", $fecha_fin);
        }

        $transacciones = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}fondo_transacciones WHERE user_id = $user_id $filtro_fechas ORDER BY fecha_registro DESC");
        $creditos = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}fondo_creditos WHERE user_id = $user_id ORDER BY fecha_solicitud DESC");
        
        $tx_module = new LUD_Module_Transacciones();
        $deuda_info = $tx_module->calcular_deuda_usuario($user_id);
        $estado_general = ($deuda_info['total_admin'] + $deuda_info['creditos'] > 0) ? 'En Mora' : 'Al Día';
        $score_crediticio = LUD_Module_Creditos::calcular_score_socio( $user_id );
        $tooltip_score = 'El score suma cuotas pagadas a tiempo, resta puntos por cuotas en mora/parcial y evalúa créditos terminados. Se normaliza de 0 a 100.';

        // Verificar si hay cambio programado
        $cambio_pendiente = get_user_meta($user_id, 'lud_acciones_programadas', true);
        $beneficiarios_extra = get_user_meta($user_id, 'lud_beneficiarios_detalle', true);
        $beneficiarios_extra = $beneficiarios_extra ? json_decode( $beneficiarios_extra, true ) : array();

        add_filter( 'admin_footer_text', '__return_empty_string' );
        add_filter( 'update_footer', '__return_empty_string', 999 );

        $creditos_activos = array();
        $creditos_pagados = array();
        foreach ( $creditos as $credito_item ) {
            if ( in_array( $credito_item->estado, array( 'activo', 'mora', 'programado' ), true ) ) {
                $creditos_activos[] = $credito_item;
            } elseif ( $credito_item->estado === 'pagado' ) {
                $creditos_pagados[] = $credito_item;
            }
        }
        ?>
        
        <p><a href="?page=lud-tesoreria&view=buscar_socio" class="button">← Volver al Directorio</a></p>
        
        <div class="lud-card" style="flex:1; min-width:300px;">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <h3 style="margin:0;">👤 <?php echo $user->display_name; ?></h3>
                    <?php if ( current_user_can('lud_manage_tesoreria') ): ?>
                        <a href="?page=lud-tesoreria&view=editar_socio&id=<?php echo $user_id; ?>" class="button button-secondary">✏️ Editar Datos</a>
                    <?php endif; ?>
                </div>
                <br>
                <table style="width:100%; text-align:left;">
                    <tr><th>Documento:</th><td><?php echo esc_html($cuenta->tipo_documento . ' ' . $cuenta->numero_documento); ?></td></tr>
                    <tr><th>Correo:</th><td><?php echo esc_html($user->user_email); ?></td></tr>
                    <tr><th>Teléfono:</th><td><?php echo esc_html($cuenta->telefono_contacto); ?></td></tr>
                    <tr><th>Dirección:</th><td><?php echo esc_html($cuenta->direccion_residencia); ?></td></tr>
                    <tr><th>Ciudad:</th><td><?php echo esc_html($cuenta->ciudad_pais); ?></td></tr>
                    <tr><th>Acciones Hoy:</th><td><?php echo $cuenta->numero_acciones; ?></td></tr>
                    <tr><th>Estado Socio:</th><td><?php echo ucfirst($cuenta->estado_socio); ?></td></tr>
                    <tr><th>Fecha de Incorporación:</th><td><?php echo $cuenta->fecha_ingreso_fondo ? date_i18n('d M Y', strtotime($cuenta->fecha_ingreso_fondo)) : 'N/D'; ?></td></tr>
                    <tr><th>Beneficiario:</th><td><?php echo esc_html($cuenta->beneficiario_nombre); ?> (<?php echo esc_html($cuenta->beneficiario_parentesco); ?>)</td></tr>
                    <tr><th>Contacto Ben:</th><td><?php echo isset($cuenta->beneficiario_telefono) ? esc_html($cuenta->beneficiario_telefono) : '-'; ?></td></tr>
                    <tr>
                        <th>Beneficiarios extra:</th>
                        <td>
                            <?php if ( ! empty( $beneficiarios_extra ) ) : ?>
                                <ul style="margin:0; padding-left:16px;">
                                    <?php foreach ( $beneficiarios_extra as $benef ) : ?>
                                        <li>
                                            <?php echo esc_html( $benef['nombre'] ?? '' ); ?>
                                            <?php if ( ! empty( $benef['parentesco'] ) ) : ?>
                                                (<?php echo esc_html( $benef['parentesco'] ); ?>)
                                            <?php endif; ?>
                                            <?php if ( ! empty( $benef['porcentaje'] ) ) : ?>
                                                - <?php echo esc_html( $benef['porcentaje'] ); ?>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else : ?>
                                <span>-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="lud-card" style="flex:1; min-width:300px; background:#f9f9f9;">
                <h3>💰 Estado de Cuenta</h3>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                    <div style="background:#fff; padding:10px; border-radius:5px;" title="Dinero que el socio ha aportado mes a mes.">
                        <small>Total Ahorrado</small><br>
                        <strong style="font-size:1.4em; color:#27ae60;">$ <?php echo number_format($cuenta->saldo_ahorro_capital); ?></strong>
                    </div>
                    <div style="background:#fff; padding:10px; border-radius:5px;" title="Ganancias generadas por los intereses.">
                        <small>Rendimientos Históricos</small><br>
                        <strong style="font-size:1.4em; color:#2980b9;">$ <?php echo number_format($cuenta->saldo_rendimientos); ?></strong>
                    </div>
                    <div style="background:#fff; padding:10px; border-radius:5px; grid-column: span 2;" title="Lo que debe hoy (Cuotas atrasadas + Saldo de Créditos)">
                        <small>Deuda Total (Admin + Créditos)</small><br>
                        <?php $total_deuda = $deuda_info['total_admin'] + $deuda_info['creditos']; ?>
                        <strong style="font-size:1.4em; color:<?php echo ($total_deuda > 0) ? '#c0392b' : '#7f8c8d'; ?>;">
                            $ <?php echo number_format($total_deuda); ?>
                        </strong>
                        <?php if($deuda_info['multa'] > 0) echo "<br><small style='color:#e74c3c'>(Incluye mora: $ ".number_format($deuda_info['multa']).")</small>"; ?>
                    </div>
                    <div style="background:#fff; padding:10px; border-radius:5px; grid-column: span 2;">
                        <small style="display:flex; gap:6px; align-items:center;">
                            Score Crediticio
                            <span title="<?php echo esc_attr( $tooltip_score ); ?>" style="font-size:0.85rem; color:#0d47a1; cursor:help;">ℹ️</span>
                        </small>
                        <strong style="font-size:1.4em; color:#2e7d32;"><?php echo number_format( $score_crediticio ); ?>/100</strong>
                    </div>
                    <?php if ( ! empty( $creditos_activos ) ): ?>
                        <?php foreach ( $creditos_activos as $credito_activo ): ?>
                            <?php
                            $amort_activo = $wpdb->get_results( $wpdb->prepare(
                                "SELECT * FROM {$wpdb->prefix}fondo_amortizacion WHERE credito_id = %d ORDER BY numero_cuota ASC",
                                $credito_activo->id
                            ) );
                            $interes_total = 0;
                            $fecha_final = '';
                            $proxima_cuota = null;
                            foreach ( $amort_activo as $fila ) {
                                $interes_total += floatval( $fila->interes_programado );
                                $fecha_final = $fila->fecha_vencimiento;
                                if ( ! $proxima_cuota && $fila->estado === 'pendiente' ) {
                                    $proxima_cuota = $fila;
                                }
                            }
                            ?>
                            <div style="background:#fff; padding:10px; border-radius:5px; grid-column: span 2;">
                                <small>Crédito <?php echo esc_html( ucfirst( $credito_activo->tipo_credito ) ); ?> (<?php echo esc_html( strtoupper( $credito_activo->estado ) ); ?>)</small>
                                <div style="font-size:0.9rem; color:#555; margin-top:6px;">
                                    Saldo restante: <strong>$ <?php echo number_format( $credito_activo->saldo_actual ); ?></strong><br>
                                    Interés total a pagar: <strong>$ <?php echo number_format( $interes_total ); ?></strong><br>
                                    Cuotas pactadas: <strong><?php echo intval( $credito_activo->plazo_meses ); ?></strong><br>
                                    Fecha final esperada: <strong><?php echo $fecha_final ? date_i18n( 'd M Y', strtotime( $fecha_final ) ) : 'N/D'; ?></strong><br>
                                    Próxima cuota: <strong><?php echo $proxima_cuota ? date_i18n( 'd M Y', strtotime( $proxima_cuota->fecha_vencimiento ) ) : 'N/D'; ?></strong>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="lud-card" style="margin-top:15px; background:#f7f9fb;">
            <h3>📌 Estado de Cuenta Detallado</h3>
            <p><strong>Estado:</strong> <?php echo $estado_general; ?></p>
            <ul style="margin-left:18px;">
                <?php if ( $deuda_info['ahorro'] > 0 ): ?><li>Ahorro en mora: $<?php echo number_format($deuda_info['ahorro']); ?></li><?php endif; ?>
                <?php if ( $deuda_info['secretaria'] > 0 ): ?><li>Cuota Secretaría pendiente: $<?php echo number_format($deuda_info['secretaria']); ?></li><?php endif; ?>
                <?php if ( $deuda_info['multa'] > 0 ): ?><li>Multa por mora: $<?php echo number_format($deuda_info['multa']); ?></li><?php endif; ?>
                <?php if ( $deuda_info['creditos'] > 0 ): ?><li>Créditos (capital+intereses): $<?php echo number_format($deuda_info['creditos']); ?></li><?php endif; ?>
                <?php if ( $deuda_info['creditos'] == 0 && $deuda_info['total_admin'] == 0 ): ?><li>Sin pendientes de cobro.</li><?php endif; ?>
                <li>Último aporte registrado: <?php echo $cuenta->fecha_ultimo_aporte ? date_i18n('d M Y', strtotime($cuenta->fecha_ultimo_aporte)) : 'No registrado'; ?></li>
            </ul>
        </div>

        <div class="lud-card" style="margin-top:20px; border-left:5px solid #2980b9;">
            <h3>🗳️ Gestión de Acciones</h3>
            
            <?php if ( $cambio_pendiente ): ?>
                <div class="lud-alert success" style="background:#e3f2fd; border:1px solid #2196f3; color:#0d47a1; padding:15px; border-radius:5px;">
                    <strong>🕒 Cambio Programado:</strong><br>
                    Este socio pasará a tener <strong><?php echo $cambio_pendiente['cantidad']; ?> acciones</strong> a partir del 
                    <strong><?php echo date_i18n('d de F Y', strtotime($cambio_pendiente['fecha_efectiva'])); ?></strong>.
                    <br><small>Motivo: <?php echo $cambio_pendiente['motivo']; ?></small>
                    
                    <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>" style="margin-top:10px;">
                        <input type="hidden" name="action" value="lud_cancelar_cambio_acciones">
                        <input type="hidden" name="user_id" value="<?php echo $user_id; ?>">
                        <?php wp_nonce_field('lud_cancel_shares', 'security'); ?>
                        <button class="button button-small button-link-delete" style="color:red;">Cancelar programación</button>
                    </form>
                </div>
            <?php else: ?>
                <p>Modificar el número de acciones. <strong>Nota:</strong> El cambio se hará efectivo automáticamente el día 1 del próximo mes.</p>
                
                <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>" style="background:#f1f1f1; padding:15px; border-radius:5px; display:flex; align-items:center; gap:15px;">
                    <input type="hidden" name="action" value="lud_actualizar_acciones">
                    <input type="hidden" name="user_id" value="<?php echo $user_id; ?>">
                    <?php wp_nonce_field('lud_update_shares', 'security'); ?>
                    
                    <div>
                        <label style="font-weight:bold;">Acciones Actuales:</label>
                        <input type="number" name="nuevas_acciones" value="<?php echo $cuenta->numero_acciones; ?>" min="0" max="10" style="width:80px; text-align:center;" aria-describedby="lud-acciones-ayuda">
                        <small id="lud-acciones-ayuda" style="display:block; color:#555;">Máximo 10 acciones por estatutos.</small>
                    </div>
                    
                    <div style="flex-grow:1;">
                        <label style="font-weight:bold;">Motivo del Cambio:</label>
                        <input type="text" name="motivo_cambio" placeholder="Ej: Aumento de capacidad, Ajuste voluntario..." required style="width:100%;">
                    </div>
                    
                    <button class="button button-primary">Programar Cambio</button>
                </form>
            <?php endif; ?>
        </div>

        <div class="lud-card" style="margin-top:20px;">
            <h3>📜 Historial de Créditos</h3>
            <?php if(empty($creditos)): ?>
                <p>No ha solicitado créditos.</p>
            <?php else: ?>
                <div style="margin-bottom:15px;">
                    <h4>Créditos activos y programados</h4>
                    <?php if ( empty( $creditos_activos ) ): ?>
                        <p>Sin créditos activos.</p>
                    <?php else: ?>
                        <?php foreach ( $creditos_activos as $c ): ?>
                            <?php
                            $amort = $wpdb->get_results( $wpdb->prepare(
                                "SELECT * FROM {$wpdb->prefix}fondo_amortizacion WHERE credito_id = %d ORDER BY numero_cuota ASC",
                                $c->id
                            ) );
                            $proxima_cuota = null;
                            $fecha_final = '';
                            foreach ( $amort as $fila ) {
                                $fecha_final = $fila->fecha_vencimiento;
                                if ( ! $proxima_cuota && $fila->estado === 'pendiente' ) {
                                    $proxima_cuota = $fila;
                                }
                            }
                            $toggle_id = 'amort_credito_' . $c->id;
                            ?>
                            <div style="background:#f7f9fb; padding:12px; border-radius:8px; margin-bottom:10px;">
                                <strong><?php echo esc_html( ucfirst( $c->tipo_credito ) ); ?></strong>
                                - Estado: <strong><?php echo esc_html( strtoupper( $c->estado ) ); ?></strong><br>
                                Inicio: <strong><?php echo $c->fecha_solicitud ? date_i18n( 'd M Y', strtotime( $c->fecha_solicitud ) ) : 'N/D'; ?></strong><br>
                                Cuotas pactadas: <strong><?php echo intval( $c->plazo_meses ); ?></strong><br>
                                Próxima cuota: <strong><?php echo $proxima_cuota ? date_i18n( 'd M Y', strtotime( $proxima_cuota->fecha_vencimiento ) ) : 'N/D'; ?></strong><br>
                                Valor próxima cuota: <strong><?php echo $proxima_cuota ? '$ ' . number_format( $proxima_cuota->valor_cuota_total ) : 'N/D'; ?></strong><br>
                                Fecha final: <strong><?php echo $fecha_final ? date_i18n( 'd M Y', strtotime( $fecha_final ) ) : 'N/D'; ?></strong><br>
                                Saldo restante: <strong>$ <?php echo number_format( $c->saldo_actual ); ?></strong><br>

                                <button class="button button-secondary" type="button" onclick="document.getElementById('<?php echo esc_attr( $toggle_id ); ?>').classList.toggle('lud-oculto');">
                                    Ver tabla de amortización
                                </button>
                                <div id="<?php echo esc_attr( $toggle_id ); ?>" class="lud-oculto" style="margin-top:10px;">
                                    <?php if ( empty( $amort ) ): ?>
                                        <p>Sin tabla de amortización registrada.</p>
                                    <?php else: ?>
                                        <table class="widefat striped">
                                            <thead>
                                                <tr>
                                                    <th>#</th>
                                                    <th>Vence</th>
                                                    <th>Capital</th>
                                                    <th>Interés</th>
                                                    <th>Total</th>
                                                    <th>Estado</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ( $amort as $fila ): ?>
                                                    <tr>
                                                        <td><?php echo intval( $fila->numero_cuota ); ?></td>
                                                        <td><?php echo date_i18n( 'd M Y', strtotime( $fila->fecha_vencimiento ) ); ?></td>
                                                        <td>$ <?php echo number_format( $fila->capital_programado ); ?></td>
                                                        <td>$ <?php echo number_format( $fila->interes_programado ); ?></td>
                                                        <td>$ <?php echo number_format( $fila->valor_cuota_total ); ?></td>
                                                        <td><?php echo esc_html( ucfirst( $fila->estado ) ); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div>
                    <h4>Créditos pagados</h4>
                    <?php if ( empty( $creditos_pagados ) ): ?>
                        <p>No hay créditos pagados.</p>
                    <?php else: ?>
                        <table class="widefat striped">
                            <thead><tr><th>Inicio</th><th>Monto</th><th>Capital pagado</th><th>Cuotas</th><th>Fin</th><th>Interés pagado</th></tr></thead>
                            <?php foreach ( $creditos_pagados as $c ): ?>
                                <?php
                                $amort_pagado = $wpdb->get_results( $wpdb->prepare(
                                    "SELECT * FROM {$wpdb->prefix}fondo_amortizacion WHERE credito_id = %d ORDER BY numero_cuota ASC",
                                    $c->id
                                ) );
                                $capital_pagado = 0;
                                $interes_pagado = 0;
                                $fecha_final = '';
                                foreach ( $amort_pagado as $fila ) {
                                    $fecha_final = $fila->fecha_vencimiento;
                                    if ( $fila->estado === 'pagado' ) {
                                        $capital_pagado += floatval( $fila->capital_programado );
                                        $interes_pagado += floatval( $fila->interes_programado );
                                    }
                                }
                                ?>
                                <tr>
                                    <td><?php echo $c->fecha_solicitud ? date_i18n( 'd M Y', strtotime( $c->fecha_solicitud ) ) : 'N/D'; ?></td>
                                    <td>$ <?php echo number_format( $c->monto_solicitado ); ?></td>
                                    <td>$ <?php echo number_format( $capital_pagado ); ?></td>
                                    <td><?php echo intval( $c->plazo_meses ); ?></td>
                                    <td><?php echo $fecha_final ? date_i18n( 'd M Y', strtotime( $fecha_final ) ) : 'N/D'; ?></td>
                                    <td>$ <?php echo number_format( $interes_pagado ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="lud-card" style="margin-top:20px;">
            <h3>🧾 Últimas Transacciones</h3>
            <form method="GET" style="margin-bottom:10px; display:flex; gap:10px; align-items:flex-end;">
                <input type="hidden" name="page" value="lud-tesoreria">
                <input type="hidden" name="view" value="detalle_socio">
                <input type="hidden" name="id" value="<?php echo $user_id; ?>">
                <div>
                    <label for="f_inicio" style="font-weight:bold;">Desde:</label><br>
                    <input type="date" id="f_inicio" name="f_inicio" value="<?php echo esc_attr($fecha_inicio); ?>">
                </div>
                <div>
                    <label for="f_fin" style="font-weight:bold;">Hasta:</label><br>
                    <input type="date" id="f_fin" name="f_fin" value="<?php echo esc_attr($fecha_fin); ?>">
                </div>
                <button class="button button-secondary" type="submit">Filtrar</button>
                <a class="button" href="?page=lud-tesoreria&view=detalle_socio&id=<?php echo $user_id; ?>">Limpiar</a>
            </form>
            <table class="widefat striped">
                <thead><tr><th>Fecha</th><th>Monto</th><th>Estado</th><th>Detalle</th><th>Comprobante</th></tr></thead>
                <?php foreach($transacciones as $t): ?>
                <tr>
                    <td><?php echo date('d/M/Y', strtotime($t->fecha_registro)); ?></td>
                    <td>$ <?php echo number_format($t->monto); ?></td>
                    <td><?php echo ucfirst($t->estado); ?></td>
                    <td><?php echo $t->detalle; ?></td>
                    <td>
                        <?php
                        // Comentario: Si existe un archivo de comprobante, se muestra enlace directo al upload seguro.
                        echo $this->construir_enlaces_comprobantes( $t->comprobante_url );
                        ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php
    }

    // --- VISTA 4: HISTORIAL DE INTERESES PAGADOS ---
    /**
     * Lista las liquidaciones de intereses pagados en años anteriores.
     */
    private function render_historial_intereses() {
        global $wpdb;
        // Consulta: Agrupa por año y usuario los intereses que ya están en estado 'liquidado' (PAGADOS)
        // Esto muestra la plata que ya salió de la caja y se entregó al socio.
        $pagos = $wpdb->get_results("
            SELECT u.display_name, m.anio, SUM(m.utilidad_asignada) as total_pagado
            FROM {$wpdb->prefix}fondo_utilidades_mensuales m
            JOIN {$wpdb->users} u ON m.user_id = u.ID
            WHERE m.estado = 'liquidado'
            GROUP BY m.user_id, m.anio
            ORDER BY m.anio DESC, u.display_name ASC
        ");
        ?>
        <div class="lud-card">
            <h3>📜 Historial de Intereses Entregados (Efectivo)</h3>
            <p>Este listado muestra el dinero que se ha entregado físicamente a cada socio en los cierres de Diciembre de cada año. <strong>Este dinero ya no está en la caja.</strong></p>
            
            <table class="widefat striped">
                <thead><tr><th>Año</th><th>Socio</th><th>Monto Entregado</th></tr></thead>
                <tbody>
                <?php if(empty($pagos)): ?>
                    <tr><td colspan="3">Aún no se han realizado liquidaciones anuales o entregas de intereses.</td></tr>
                <?php else: ?>
                    <?php foreach($pagos as $p): ?>
                    <tr>
                        <td><strong><?php echo $p->anio; ?></strong></td>
                        <td><?php echo $p->display_name; ?></td>
                        <td style="color:#2e7d32; font-weight:bold;">$ <?php echo number_format($p->total_pagado); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Muestra resumen anual de recaudos por concepto.
     */
    private function render_historial_anual() {
        global $wpdb;

        $resumen = $wpdb->get_results("
            SELECT
                YEAR(fecha_recaudo) AS anio,
                SUM(CASE WHEN concepto = 'ahorro' THEN monto ELSE 0 END) AS total_ahorro,
                SUM(CASE WHEN concepto = 'capital_credito' THEN monto ELSE 0 END) AS total_capital,
                SUM(CASE WHEN concepto = 'interes_credito' THEN monto ELSE 0 END) AS total_interes,
                SUM(CASE WHEN concepto = 'multa' THEN monto ELSE 0 END) AS total_multa,
                SUM(CASE WHEN concepto = 'cuota_secretaria' THEN monto ELSE 0 END) AS total_secretaria,
                SUM(CASE WHEN concepto = 'excedente' THEN monto ELSE 0 END) AS total_cuota_mixta
            FROM {$wpdb->prefix}fondo_recaudos_detalle
            GROUP BY YEAR(fecha_recaudo)
            ORDER BY anio DESC
        ");
        ?>
        <div class="lud-card">
            <h3>🧮 Históricos Anuales del Fondo</h3>
            <p>Este resumen consolida el recaudo por año. La liquidación de intereses se calcula año a año y no mezcla periodos anteriores en el cálculo vigente.</p>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Año</th>
                        <th>Ahorro</th>
                        <th>Capital Créditos</th>
                        <th>Intereses</th>
                        <th>Multas</th>
                        <th>Secretaría</th>
                        <th>Cuota Mixta</th>
                        <th>Total Recaudo</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $resumen ) ) : ?>
                    <tr><td colspan="8">Aún no hay recaudos históricos registrados.</td></tr>
                <?php else : ?>
                    <?php foreach ( $resumen as $fila ) : ?>
                        <?php
                        $total = floatval( $fila->total_ahorro ) + floatval( $fila->total_capital ) + floatval( $fila->total_interes )
                               + floatval( $fila->total_multa ) + floatval( $fila->total_secretaria ) + floatval( $fila->total_cuota_mixta );
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html( $fila->anio ); ?></strong></td>
                            <td>$ <?php echo number_format( $fila->total_ahorro ); ?></td>
                            <td>$ <?php echo number_format( $fila->total_capital ); ?></td>
                            <td>$ <?php echo number_format( $fila->total_interes ); ?></td>
                            <td>$ <?php echo number_format( $fila->total_multa ); ?></td>
                            <td>$ <?php echo number_format( $fila->total_secretaria ); ?></td>
                            <td>$ <?php echo number_format( $fila->total_cuota_mixta ); ?></td>
                            <td><strong>$ <?php echo number_format( $total ); ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Muestra resumen anual de recaudos por concepto.
     */
    private function render_historial_anual() {
        global $wpdb;

        $resumen = $wpdb->get_results("
            SELECT
                YEAR(fecha_recaudo) AS anio,
                SUM(CASE WHEN concepto = 'ahorro' THEN monto ELSE 0 END) AS total_ahorro,
                SUM(CASE WHEN concepto = 'capital_credito' THEN monto ELSE 0 END) AS total_capital,
                SUM(CASE WHEN concepto = 'interes_credito' THEN monto ELSE 0 END) AS total_interes,
                SUM(CASE WHEN concepto = 'multa' THEN monto ELSE 0 END) AS total_multa,
                SUM(CASE WHEN concepto = 'cuota_secretaria' THEN monto ELSE 0 END) AS total_secretaria,
                SUM(CASE WHEN concepto = 'excedente' THEN monto ELSE 0 END) AS total_cuota_mixta
            FROM {$wpdb->prefix}fondo_recaudos_detalle
            GROUP BY YEAR(fecha_recaudo)
            ORDER BY anio DESC
        ");
        ?>
        <div class="lud-card">
            <h3>🧮 Históricos Anuales del Fondo</h3>
            <p>Este resumen consolida el recaudo por año. La liquidación de intereses se calcula año a año y no mezcla periodos anteriores en el cálculo vigente.</p>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Año</th>
                        <th>Ahorro</th>
                        <th>Capital Créditos</th>
                        <th>Intereses</th>
                        <th>Multas</th>
                        <th>Secretaría</th>
                        <th>Cuota Mixta</th>
                        <th>Total Recaudo</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $resumen ) ) : ?>
                    <tr><td colspan="8">Aún no hay recaudos históricos registrados.</td></tr>
                <?php else : ?>
                    <?php foreach ( $resumen as $fila ) : ?>
                        <?php
                        $total = floatval( $fila->total_ahorro ) + floatval( $fila->total_capital ) + floatval( $fila->total_interes )
                               + floatval( $fila->total_multa ) + floatval( $fila->total_secretaria ) + floatval( $fila->total_cuota_mixta );
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html( $fila->anio ); ?></strong></td>
                            <td>$ <?php echo number_format( $fila->total_ahorro ); ?></td>
                            <td>$ <?php echo number_format( $fila->total_capital ); ?></td>
                            <td>$ <?php echo number_format( $fila->total_interes ); ?></td>
                            <td>$ <?php echo number_format( $fila->total_multa ); ?></td>
                            <td>$ <?php echo number_format( $fila->total_secretaria ); ?></td>
                            <td>$ <?php echo number_format( $fila->total_cuota_mixta ); ?></td>
                            <td><strong>$ <?php echo number_format( $total ); ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Muestra resumen anual de recaudos por concepto.
     */
    private function render_historial_anual() {
        global $wpdb;

        $resumen = $wpdb->get_results("
            SELECT
                YEAR(fecha_recaudo) AS anio,
                SUM(CASE WHEN concepto = 'ahorro' THEN monto ELSE 0 END) AS total_ahorro,
                SUM(CASE WHEN concepto = 'capital_credito' THEN monto ELSE 0 END) AS total_capital,
                SUM(CASE WHEN concepto = 'interes_credito' THEN monto ELSE 0 END) AS total_interes,
                SUM(CASE WHEN concepto = 'multa' THEN monto ELSE 0 END) AS total_multa,
                SUM(CASE WHEN concepto = 'cuota_secretaria' THEN monto ELSE 0 END) AS total_secretaria,
                SUM(CASE WHEN concepto = 'excedente' THEN monto ELSE 0 END) AS total_cuota_mixta
            FROM {$wpdb->prefix}fondo_recaudos_detalle
            GROUP BY YEAR(fecha_recaudo)
            ORDER BY anio DESC
        ");
        ?>
        <div class="lud-card">
            <h3>🧮 Históricos Anuales del Fondo</h3>
            <p>Este resumen consolida el recaudo por año. La liquidación de intereses se calcula año a año y no mezcla periodos anteriores en el cálculo vigente.</p>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Año</th>
                        <th>Ahorro</th>
                        <th>Capital Créditos</th>
                        <th>Intereses</th>
                        <th>Multas</th>
                        <th>Secretaría</th>
                        <th>Cuota Mixta</th>
                        <th>Total Recaudo</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $resumen ) ) : ?>
                    <tr><td colspan="8">Aún no hay recaudos históricos registrados.</td></tr>
                <?php else : ?>
                    <?php foreach ( $resumen as $fila ) : ?>
                        <?php
                        $total = floatval( $fila->total_ahorro ) + floatval( $fila->total_capital ) + floatval( $fila->total_interes )
                               + floatval( $fila->total_multa ) + floatval( $fila->total_secretaria ) + floatval( $fila->total_cuota_mixta );
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html( $fila->anio ); ?></strong></td>
                            <td>$ <?php echo number_format( $fila->total_ahorro ); ?></td>
                            <td>$ <?php echo number_format( $fila->total_capital ); ?></td>
                            <td>$ <?php echo number_format( $fila->total_interes ); ?></td>
                            <td>$ <?php echo number_format( $fila->total_multa ); ?></td>
                            <td>$ <?php echo number_format( $fila->total_secretaria ); ?></td>
                            <td>$ <?php echo number_format( $fila->total_cuota_mixta ); ?></td>
                            <td><strong>$ <?php echo number_format( $total ); ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Muestra resumen anual de recaudos por concepto.
     */
    private function render_historial_anual() {
        global $wpdb;

        $resumen = $wpdb->get_results("
            SELECT
                YEAR(fecha_recaudo) AS anio,
                SUM(CASE WHEN concepto = 'ahorro' THEN monto ELSE 0 END) AS total_ahorro,
                SUM(CASE WHEN concepto = 'capital_credito' THEN monto ELSE 0 END) AS total_capital,
                SUM(CASE WHEN concepto = 'interes_credito' THEN monto ELSE 0 END) AS total_interes,
                SUM(CASE WHEN concepto = 'multa' THEN monto ELSE 0 END) AS total_multa,
                SUM(CASE WHEN concepto = 'cuota_secretaria' THEN monto ELSE 0 END) AS total_secretaria,
                SUM(CASE WHEN concepto = 'excedente' THEN monto ELSE 0 END) AS total_cuota_mixta
            FROM {$wpdb->prefix}fondo_recaudos_detalle
            GROUP BY YEAR(fecha_recaudo)
            ORDER BY anio DESC
        ");
        ?>
        <div class="lud-card">
            <h3>🧮 Históricos Anuales del Fondo</h3>
            <p>Este resumen consolida el recaudo por año. La liquidación de intereses se calcula año a año y no mezcla periodos anteriores en el cálculo vigente.</p>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Año</th>
                        <th>Ahorro</th>
                        <th>Capital Créditos</th>
                        <th>Intereses</th>
                        <th>Multas</th>
                        <th>Secretaría</th>
                        <th>Cuota Mixta</th>
                        <th>Total Recaudo</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $resumen ) ) : ?>
                    <tr><td colspan="8">Aún no hay recaudos históricos registrados.</td></tr>
                <?php else : ?>
                    <?php foreach ( $resumen as $fila ) : ?>
                        <?php
                        $total = floatval( $fila->total_ahorro ) + floatval( $fila->total_capital ) + floatval( $fila->total_interes )
                               + floatval( $fila->total_multa ) + floatval( $fila->total_secretaria ) + floatval( $fila->total_cuota_mixta );
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html( $fila->anio ); ?></strong></td>
                            <td>$ <?php echo number_format( $fila->total_ahorro ); ?></td>
                            <td>$ <?php echo number_format( $fila->total_capital ); ?></td>
                            <td>$ <?php echo number_format( $fila->total_interes ); ?></td>
                            <td>$ <?php echo number_format( $fila->total_multa ); ?></td>
                            <td>$ <?php echo number_format( $fila->total_secretaria ); ?></td>
                            <td>$ <?php echo number_format( $fila->total_cuota_mixta ); ?></td>
                            <td><strong>$ <?php echo number_format( $total ); ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Vista para marcar asistencia a asambleas y generar multas por inasistencia.
     */
    private function render_control_asistencia() {
        if ( ! current_user_can( 'lud_manage_tesoreria' ) ) {
            wp_die( 'Acceso denegado' );
        }

        global $wpdb;
        $socios = $wpdb->get_results("
            SELECT u.ID, u.display_name, u.user_email
            FROM {$wpdb->users} u
            JOIN {$wpdb->prefix}fondo_cuentas c ON u.ID = c.user_id
            WHERE c.estado_socio = 'activo'
            ORDER BY u.display_name ASC
        ");
        $fecha_asamblea = isset( $_GET['fecha_asamblea'] ) ? sanitize_text_field( $_GET['fecha_asamblea'] ) : date( 'Y-m-d' );

        if ( isset( $_GET['msg'] ) && $_GET['msg'] === 'asistencia_guardada' ) {
            echo '<div class="notice notice-success"><p>✅ Asistencia registrada y multas de inasistencia generadas.</p></div>';
        }
        ?>
        <div class="lud-card">
            <h3>🗓️ Control de Asistencia</h3>
            <p>Marca los socios que asistieron a la asamblea. Los no marcados recibirán una multa automática de $10.000 por "Inasistencia Asamblea".</p>

            <form method="POST" action="<?php echo admin_url( 'admin-post.php' ); ?>">
                <input type="hidden" name="action" value="lud_guardar_asistencia">
                <?php wp_nonce_field( 'lud_asistencia_nonce', 'security' ); ?>

                <label class="lud-label" for="fecha_asamblea">Fecha de la asamblea</label>
                <input type="date" id="fecha_asamblea" name="fecha_asamblea" class="lud-input" value="<?php echo esc_attr( $fecha_asamblea ); ?>" required style="max-width:220px;">

                <table class="widefat striped" style="margin-top:15px;">
                    <thead><tr><th>Asistió</th><th>Socio</th><th>Correo</th></tr></thead>
                    <tbody>
                        <?php if ( empty( $socios ) ): ?>
                            <tr><td colspan="3">No hay socios activos.</td></tr>
                        <?php else: ?>
                            <?php foreach ( $socios as $s ): ?>
                                <tr>
                                    <td style="width:90px;">
                                        <label style="display:flex; align-items:center; gap:6px;">
                                            <input type="checkbox" name="socio_presente[]" value="<?php echo $s->ID; ?>" checked>
                                            <span>Presente</span>
                                        </label>
                                    </td>
                                    <td><?php echo esc_html( $s->display_name ); ?></td>
                                    <td><?php echo esc_html( $s->user_email ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <p style="margin-top:15px; color:#555;">Tip: deja desmarcados a los ausentes antes de guardar.</p>
                <button type="submit" class="button button-primary button-hero">💾 Guardar asistencia</button>
            </form>
        </div>
        <?php
    }

    /**
     * Panel exclusivo de presidencia para aprobar/rechazar registros de nuevos socios.
     */
    private function render_panel_presidencia() {
        if ( ! $this->usuario_es_presidencia() ) {
            wp_die( 'Acceso restringido a Presidencia' );
        }

        global $wpdb;
        $pendientes = $wpdb->get_results("
            SELECT c.*, u.display_name, u.user_email
            FROM {$wpdb->prefix}fondo_cuentas c
            JOIN {$wpdb->users} u ON c.user_id = u.ID
            WHERE c.estado_socio = 'pendiente'
            ORDER BY c.fecha_ingreso_fondo ASC
        ");

        $historial = $wpdb->get_results("
            SELECT t.*, u.display_name
            FROM {$wpdb->prefix}fondo_transacciones t
            JOIN {$wpdb->users} u ON t.user_id = u.ID
            WHERE t.detalle LIKE '%ADMISION%'
            ORDER BY t.fecha_registro DESC
            LIMIT 20
        ");

        if ( isset( $_GET['msg'] ) && $_GET['msg'] === 'admision_ok' ) {
            echo '<div class="notice notice-success"><p>✅ Solicitud actualizada.</p></div>';
        } elseif ( isset( $_GET['msg'] ) && $_GET['msg'] === 'admision_err' ) {
            echo '<div class="notice notice-error"><p>⚠️ Ocurrió un error al procesar la solicitud.</p></div>';
        }
        ?>
        <div class="lud-card" style="margin-bottom:20px;">
            <h3>🏛️ Solicitudes pendientes</h3>
            <?php if ( empty( $pendientes ) ): ?>
                <p style="color:#2e7d32;">No hay solicitudes en espera.</p>
            <?php else: ?>
                <table class="widefat striped">
                    <thead><tr><th>Socio</th><th>Documento</th><th>Teléfono</th><th>Aporte inicial</th><th>Acciones</th></tr></thead>
                    <tbody>
                        <?php foreach ( $pendientes as $p ): ?>
                            <tr>
                                <td><strong><?php echo esc_html( $p->display_name ); ?></strong><br><small><?php echo esc_html( $p->user_email ); ?></small></td>
                                <td><?php echo esc_html( $p->tipo_documento . ' ' . $p->numero_documento ); ?></td>
                                <td><?php echo esc_html( $p->telefono_contacto ); ?></td>
                                <td>$ <?php echo number_format( $p->aporte_inicial ); ?></td>
                                <td>
                                    <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                        <a href="<?php echo admin_url('admin-post.php?action=lud_ver_comprobante&file=documentos/'.$p->url_documento_id); ?>" target="_blank" class="button">📄 Documento</a>
                                        <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>">
                                            <input type="hidden" name="action" value="lud_aprobar_registro">
                                            <input type="hidden" name="cuenta_id" value="<?php echo $p->id; ?>">
                                            <?php wp_nonce_field('aprobar_socio_'.$p->id, 'security'); ?>
                                            <button class="button button-primary">Aprobar</button>
                                        </form>
                                        <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>" style="display:flex; flex-direction:column; gap:6px; min-width:200px;">
                                            <input type="hidden" name="action" value="lud_rechazar_registro">
                                            <input type="hidden" name="cuenta_id" value="<?php echo $p->id; ?>">
                                            <?php wp_nonce_field('rechazar_socio_'.$p->id, 'security'); ?>
                                            <textarea name="motivo_rechazo" rows="2" class="lud-input" placeholder="Motivo de rechazo" required></textarea>
                                            <button class="button button-link-delete" onclick="return confirm('¿Rechazar solicitud?');">Rechazar</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="lud-card">
            <h3>🧾 Historial de decisiones</h3>
            <?php if ( empty( $historial ) ): ?>
                <p style="color:#777;">Sin movimientos de admisión registrados.</p>
            <?php else: ?>
                <ul style="margin:0; padding-left:18px;">
                    <?php foreach ( $historial as $h ): ?>
                        <li>
                            <strong><?php echo esc_html( $h->display_name ); ?></strong>
                            <span style="color:#555;">(<?php echo date_i18n('d/m/Y H:i', strtotime($h->fecha_registro)); ?>)</span><br>
                            <small style="color:#666;"><?php echo esc_html( $h->detalle ); ?></small>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Vista de configuración de correos y pruebas SMTP para administradores.
     */
    private function render_configuracion_fondo() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Acceso denegado' );
        }

        $config = lud_notificaciones()->obtener_configuracion();

        if ( isset( $_GET['conf'] ) && $_GET['conf'] === 'ok' ) {
            echo '<div class="notice notice-success"><p>✅ Configuración de correos actualizada.</p></div>';
        } elseif ( isset( $_GET['conf'] ) && $_GET['conf'] === 'test' ) {
            echo '<div class="notice notice-success"><p>✉️ Correo de prueba enviado. Revisa tu bandeja.</p></div>';
        }
        ?>
        <div class="lud-card" style="margin-bottom:20px;">
            <h2>⚙️ Configurador de correos electrónicos</h2>
            <p>Define logo, enlaces y textos de pie que se usarán en todas las notificaciones automáticas.</p>
            <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="lud_guardar_config_correo">
                <?php wp_nonce_field( 'lud_config_correo', 'lud_seguridad' ); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row"><label>URL del logo para correos</label></th>
                        <td><input type="url" name="logo_url" class="regular-text" value="<?php echo esc_attr( $config['logo_url'] ); ?>" placeholder="https://.../logo.png"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Enlace al portal</label></th>
                        <td><input type="url" name="url_portal" class="regular-text" value="<?php echo esc_attr( $config['url_portal'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Enlace a políticas</label></th>
                        <td><input type="url" name="enlace_politicas" class="regular-text" value="<?php echo esc_attr( $config['enlace_politicas'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Enlace para actualizar datos</label></th>
                        <td><input type="url" name="enlace_actualizacion_datos" class="regular-text" value="<?php echo esc_attr( $config['enlace_actualizacion_datos'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Nombre remitente</label></th>
                        <td><input type="text" name="remitente_nombre" class="regular-text" value="<?php echo esc_attr( $config['remitente_nombre'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Texto de pie</label></th>
                        <td><textarea name="texto_pie" class="large-text" rows="3"><?php echo esc_textarea( $config['texto_pie'] ); ?></textarea></td>
                    </tr>
                </table>
                <p class="submit">
                    <button class="button button-primary">💾 Guardar configuración</button>
                </p>
            </form>
        </div>

        <div class="lud-card" style="border-left:5px solid #1565c0;">
            <h2>🧪 LUD Test (correo de prueba)</h2>
            <p>Envía un correo de prueba con la plantilla activa para validar tu configuración SMTP.</p>
            <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="lud_enviar_test_correo">
                <?php wp_nonce_field( 'lud_test_correo', 'lud_seguridad' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label>Destino</label></th>
                        <td><input type="email" name="correo_destino" class="regular-text" required value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>"></td>
                    </tr>
                </table>
                <p class="submit">
                    <button class="button">🚀 Enviar prueba</button>
                </p>
            </form>
        </div>
        <?php
    }

    // =============================================================
    // AUTOMATIZACIÓN DEL CIERRE MENSUAL
    // =============================================================

    /**
     * Verifica si falta calcular la utilidad del mes anterior.
     * Se ejecuta silenciosamente al cargar el dashboard.
     */

    // --- FUNCIÓN RECUPERADA PARA DEBUG Y PRUEBAS ---

    /**
     * Verifica si falta cerrar el mes anterior y ejecuta el cálculo si aplica.
     */
    public function verificar_cierre_automatico() {
        // Solo ejecuta si el usuario es Admin o Tesorero (para no sobrecargar si entra un socio cualquiera)
        if ( ! current_user_can('lud_manage_tesoreria') ) return;

        global $wpdb;
        $mes_actual = intval(date('m'));
        $anio_actual = intval(date('Y'));
        
        // Determinar mes anterior (el que se debe cerrar)
        $mes_cierre = $mes_actual - 1;
        $anio_cierre = $anio_actual;
        
        if ( $mes_cierre == 0 ) { // Si estamos en Enero, cerramos Diciembre del año pasado
            $mes_cierre = 12;
            $anio_cierre = $anio_actual - 1;
        }

        // Verificar si ya existe cierre para ese mes
        $existe = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fondo_utilidades_mensuales WHERE mes = $mes_cierre AND anio = $anio_cierre");
        
        if ( $existe == 0 ) {
            // ¡NO EXISTE! Ejecutar cálculo automático ahora mismo.
            $this->calcular_utilidad_mes_especifico($mes_cierre, $anio_cierre);
        }
    }

    /**
     * Calcula la utilidad generada en un mes y año concretos.
     */
    private function calcular_utilidad_mes_especifico($mes, $anio) {
        global $wpdb;
        
        // 1. Calcular Utilidad Neta del Mes (Ingresos - Gastos)
        $ingresos = $wpdb->get_var("SELECT SUM(monto) FROM {$wpdb->prefix}fondo_recaudos_detalle WHERE concepto IN ('interes_credito', 'multa') AND MONTH(fecha_recaudo) = $mes AND YEAR(fecha_recaudo) = $anio");
        $gastos = $wpdb->get_var("SELECT SUM(monto) FROM {$wpdb->prefix}fondo_gastos WHERE MONTH(fecha_gasto) = $mes AND YEAR(fecha_gasto) = $anio");
        $utilidad_neta = floatval($ingresos) - floatval($gastos);
        
        // Si no hubo ganancia, registramos un cierre en 0 para que no se intente correr de nuevo
        if ( $utilidad_neta <= 0 ) {
            // Crear registro dummy para marcar el mes como cerrado
            $admin_id = get_current_user_id();
            $wpdb->insert("{$wpdb->prefix}fondo_utilidades_mensuales", ['user_id' => $admin_id, 'mes' => $mes, 'anio' => $anio, 'acciones_mes' => 0, 'utilidad_asignada' => 0, 'estado' => 'provisional']);
            return;
        }

        // 2. Repartir entre socios al día
        // Lógica: Solo participan quienes pagaron su cuota de ahorro EN ESE MES.
        $socios = $wpdb->get_results("SELECT user_id, numero_acciones FROM {$wpdb->prefix}fondo_cuentas WHERE estado_socio = 'activo'");
        $total_acciones_validas = 0;
        $socios_habiles = [];

        foreach($socios as $s) {
            // Verificar si el socio pagó la cuota de 'ahorro' en ese mes específico
            $pago_mes = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fondo_recaudos_detalle WHERE user_id = {$s->user_id} AND concepto = 'ahorro' AND MONTH(fecha_recaudo) = $mes AND YEAR(fecha_recaudo) = $anio");
            
            if ($pago_mes > 0) {
                $total_acciones_validas += intval($s->numero_acciones);
                $socios_habiles[] = $s;
            }
        }

        if ( $total_acciones_validas > 0 ) {
            $valor_por_accion = $utilidad_neta / $total_acciones_validas;
            foreach ( $socios_habiles as $s ) {
                $ganancia = $s->numero_acciones * $valor_por_accion;
                $wpdb->insert("{$wpdb->prefix}fondo_utilidades_mensuales", [
                    'user_id' => $s->user_id, 'mes' => $mes, 'anio' => $anio,
                    'acciones_mes' => $s->numero_acciones, 'utilidad_asignada' => $ganancia, 'estado' => 'provisional'
                ]);
            }
        }
    }

    // =============================================================
    // LOGICA DE NEGOCIO (SIN CAMBIOS)
    // =============================================================

    /**
     * Aprueba un pago pendiente y genera el desglose financiero.
     */
    public function procesar_aprobacion() {
        if ( ! current_user_can( 'lud_manage_tesoreria' ) ) wp_die('Acceso denegado: No tienes permisos de Tesorero.');
        $tx_id = intval( $_POST['tx_id'] );
        if ( ! wp_verify_nonce( $_POST['security'], 'aprobar_' . $tx_id ) ) wp_die('Seguridad fallida');

        global $wpdb;
        $tx = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}fondo_transacciones WHERE id = $tx_id" );
        if ( ! $tx || $tx->estado != 'pendiente' ) wp_die('Transacción inválida');

        $dinero_disponible = floatval( $tx->monto );
        
        $cuenta = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}fondo_cuentas WHERE user_id = {$tx->user_id}" );
        $acciones = $cuenta ? intval($cuenta->numero_acciones) : 1;
        $valor_cuota_ahorro = $acciones * 50000;
        $valor_cuota_secretaria = $acciones * 1000;

        $wpdb->query('START TRANSACTION');
        try {
            $fecha_reporte = new DateTime( $tx->fecha_registro );
            $fecha_ultimo = $cuenta->fecha_ultimo_aporte ? $cuenta->fecha_ultimo_aporte : date('Y-m-01');
            $inicio = new DateTime( $fecha_ultimo );
            $inicio->modify( 'first day of next month' );
            
            $debe_ahorro = 0; $debe_secretaria = 0; $debe_multa = 0;

            $cursor = clone $inicio;
            while ( $cursor <= $fecha_reporte ) {
                $debe_ahorro += $valor_cuota_ahorro;
                $debe_secretaria += $valor_cuota_secretaria;
                $limite = clone $cursor;
                $limite->setDate( $cursor->format('Y'), $cursor->format('m'), 5 ); 
                if ( $fecha_reporte > $limite ) {
                    $dias = $fecha_reporte->diff($limite)->days;
                    $debe_multa += ($dias * 1000 * $acciones);
                }
                $cursor->modify( 'first day of next month' );
            }

            $table_recaudos = $wpdb->prefix . 'fondo_recaudos_detalle';
            
            if ( $debe_multa > 0 && $dinero_disponible > 0 ) {
                $cobrado = min( $dinero_disponible, $debe_multa );
                $dinero_disponible -= $cobrado;
                $wpdb->insert( $table_recaudos, ['transaccion_id' => $tx->id, 'user_id' => $tx->user_id, 'concepto' => 'multa', 'monto' => $cobrado, 'fecha_recaudo' => current_time('mysql')] );
            }

            if ( $debe_secretaria > 0 && $dinero_disponible > 0 ) {
                $cobrado = min( $dinero_disponible, $debe_secretaria );
                $dinero_disponible -= $cobrado;
                $wpdb->insert( $table_recaudos, ['transaccion_id' => $tx->id, 'user_id' => $tx->user_id, 'concepto' => 'cuota_secretaria', 'monto' => $cobrado, 'fecha_recaudo' => current_time('mysql')] );
            }

            $ahorro_cobrado = 0;
            if ( $debe_ahorro > 0 && $dinero_disponible > 0 ) {
                $cobrado = min( $dinero_disponible, $debe_ahorro );
                $dinero_disponible -= $cobrado;
                $ahorro_cobrado += $cobrado;
                $wpdb->insert( $table_recaudos, ['transaccion_id' => $tx->id, 'user_id' => $tx->user_id, 'concepto' => 'ahorro', 'monto' => $cobrado, 'fecha_recaudo' => current_time('mysql')] );
            }

            if ( $dinero_disponible > 0 ) {
                $creditos = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}fondo_creditos WHERE user_id = {$tx->user_id} AND estado IN ('activo', 'mora')");

                foreach ($creditos as $cred) {
                    if ( $dinero_disponible <= 0 ) break;

                    $saldo = floatval($cred->saldo_actual);
                    
                    // A. CALCULAR MORA (Solo Ágil)
                    $cobro_mora = 0;
                    if ( $cred->tipo_credito == 'agil' ) {
                        $f_aprob = new DateTime($cred->fecha_aprobacion);
                        $f_venc = clone $f_aprob;
                        $f_venc->modify('+1 month');
                        $hoy = new DateTime();

                        if ( $hoy > $f_venc ) {
                            $dias = $hoy->diff($f_venc)->days;
                            $mora_teorica = $saldo * 0.04 * ($dias / 30);
                            
                            // Cobramos lo que alcance
                            $cobro_mora = min($dinero_disponible, $mora_teorica);
                            
                            if ( $cobro_mora > 0 ) {
                                $dinero_disponible -= $cobro_mora;
                                $wpdb->insert( $table_recaudos, [
                                    'transaccion_id' => $tx->id, 'user_id' => $tx->user_id, 
                                    'concepto' => 'interes_mora_credito', // Concepto Nuevo
                                    'monto' => $cobro_mora, 'fecha_recaudo' => current_time('mysql')
                                ]);
                            }
                        }
                    }

                    // B. CALCULAR INTERÉS CORRIENTE (Solo Ágil por ahora en este bloque dinámico)
                    // Para créditos corrientes, el interés suele ir en la cuota fija, pero aquí priorizamos Ágil.
                    $cobro_interes = 0;
                    if ( $cred->tipo_credito == 'agil' && $dinero_disponible > 0 ) {
                        $interes_teorico = $saldo * 0.015;
                        $cobro_interes = min($dinero_disponible, $interes_teorico);
                        
                        if ( $cobro_interes > 0 ) {
                            $dinero_disponible -= $cobro_interes;
                            $wpdb->insert( $table_recaudos, [
                                'transaccion_id' => $tx->id, 'user_id' => $tx->user_id, 
                                'concepto' => 'interes_credito', 
                                'monto' => $cobro_interes, 'fecha_recaudo' => current_time('mysql')
                            ]);
                        }
                    }

                    // C. ABONO A CAPITAL (Lo que sobre)
                    if ( $dinero_disponible > 0 ) {
                        // El abono no puede superar el saldo
                        $abono_capital = min($dinero_disponible, $saldo);
                        $dinero_disponible -= $abono_capital;
                        
                        $wpdb->insert( $table_recaudos, [
                            'transaccion_id' => $tx->id, 'user_id' => $tx->user_id, 
                            'concepto' => 'capital_credito', 
                            'monto' => $abono_capital, 'fecha_recaudo' => current_time('mysql')
                        ]);

                        // Actualizar Saldo en DB
                        $nuevo_saldo = $saldo - $abono_capital;
                        $nuevo_estado = ($nuevo_saldo <= 0) ? 'pagado' : 'activo';
                        
                        // Si pagó y tenía mora, limpiamos estado
                        if ( $cred->estado == 'mora' && $nuevo_estado == 'activo' ) {
                             // Sigue en mora si debe (para corrientes), pero en Ágil el estado depende del tiempo.
                             // Dejamos 'activo' o 'pagado'.
                        }

                        $wpdb->update( 
                            "{$wpdb->prefix}fondo_creditos", 
                            ['saldo_actual' => $nuevo_saldo, 'estado' => $nuevo_estado], 
                            ['id' => $cred->id] 
                        );
                    }
                }
            }

            if ( $ahorro_cobrado > 0 ) {
                $nueva_fecha = ($ahorro_cobrado >= $debe_ahorro) ? $fecha_reporte->format('Y-m-d') : $cuenta->fecha_ultimo_aporte;
                $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}fondo_cuentas SET saldo_ahorro_capital = saldo_ahorro_capital + %f, fecha_ultimo_aporte = %s WHERE user_id = %d", $ahorro_cobrado, $nueva_fecha, $tx->user_id));
            }

            $detalle_final = $tx->detalle;
            $wpdb->update( $wpdb->prefix . 'fondo_transacciones', ['estado' => 'aprobado', 'aprobado_por' => get_current_user_id(), 'fecha_aprobacion' => current_time('mysql'), 'detalle' => $detalle_final], ['id' => $tx->id] );

            $wpdb->query('COMMIT');

            // Desglose para notificación automática al socio.
            $desglose_rows = $wpdb->get_results( $wpdb->prepare( "SELECT concepto, SUM(monto) as total FROM {$wpdb->prefix}fondo_recaudos_detalle WHERE transaccion_id = %d GROUP BY concepto", $tx->id ), ARRAY_A );
            $desglose_envio = array();
            foreach ( $desglose_rows as $row ) {
                $desglose_envio[ $row['concepto'] ] = floatval( $row['total'] );
            }
            do_action( 'lud_evento_pago_aprobado', $tx->user_id, $tx->id, $tx->monto, $desglose_envio );

            wp_redirect( admin_url( 'admin.php?page=lud-tesoreria&msg=approved' ) );
            exit;
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_die('Error: ' . $e->getMessage());
        }
    }

    /**
     * Marca un pago como rechazado y notifica en el historial.
     */
    public function procesar_rechazo() {
        if ( ! current_user_can( 'lud_manage_tesoreria' ) ) wp_die('Acceso denegado: No tienes permisos de Tesorero.');
        $tx_id = intval( $_POST['tx_id'] );
        if ( ! wp_verify_nonce( $_POST['security'], 'rechazar_' . $tx_id ) ) wp_die('Seguridad fallida');
        global $wpdb;
        $motivo = sanitize_text_field( $_POST['motivo'] );
        $detalle = $wpdb->get_var("SELECT detalle FROM {$wpdb->prefix}fondo_transacciones WHERE id = $tx_id");
        $wpdb->update( $wpdb->prefix . 'fondo_transacciones', ['estado' => 'rechazado', 'aprobado_por' => get_current_user_id(), 'fecha_aprobacion' => current_time('mysql'), 'detalle' => $detalle . " || RECHAZADO: $motivo"], ['id' => $tx_id] );
        $user_id = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}fondo_transacciones WHERE id = %d", $tx_id ) );
        if ( $user_id ) {
            do_action( 'lud_evento_pago_rechazado', $user_id, $tx_id, $motivo );
        }
        wp_redirect( admin_url( 'admin.php?page=lud-tesoreria&msg=rejected' ) );
        exit;
    }

    /**
     * Desembolsa un crédito pendiente, mueve saldos y genera tabla de amortización.
     */
    public function procesar_desembolso() {
        if ( ! current_user_can( 'lud_manage_tesoreria' ) ) wp_die('Acceso denegado: No tienes permisos de Tesorero.');
        check_admin_referer('lud_approve_credit', 'security');
        global $wpdb;
        $id = intval($_POST['credito_id']);
        $entrega = sanitize_text_field($_POST['datos_entrega']);
        $credito = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}fondo_creditos WHERE id=$id");
        if (!$credito) wp_die("No encontrado");

        $wpdb->update( $wpdb->prefix.'fondo_creditos', ['estado' => 'activo', 'monto_aprobado' => $credito->monto_solicitado, 'saldo_actual' => $credito->monto_solicitado, 'datos_entrega' => $entrega, 'fecha_aprobacion' => current_time('mysql')], ['id' => $id] );

        $this->generar_tabla_amortizacion($id);

        require_once LUD_PLUGIN_DIR . 'includes/class-module-creditos.php';
        $credito_actualizado = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}fondo_creditos WHERE id=$id");
        $documentos_pdf = LUD_Module_Creditos::generar_pdf_final_static($credito_actualizado);

        $adjuntos = array();
        if ( is_array( $documentos_pdf ) ) {
            if ( ! empty( $documentos_pdf['contrato'] ) ) {
                $adjuntos[] = WP_CONTENT_DIR . "/uploads/fondo_seguro/contratos/" . $documentos_pdf['contrato'];
            }
            if ( ! empty( $documentos_pdf['pagare'] ) ) {
                $adjuntos[] = WP_CONTENT_DIR . "/uploads/fondo_seguro/contratos/" . $documentos_pdf['pagare'];
            }
        }

        do_action( 'lud_evento_credito_decision', $credito->user_id, 'activo', $id, $entrega, $adjuntos );

        // Registrar el desembolso en el historial del socio con contrato firmado.
        $comprobantes_guardados = array();
        if ( is_array( $documentos_pdf ) ) {
            if ( ! empty( $documentos_pdf['contrato'] ) ) {
                $comprobantes_guardados[] = 'contratos/' . sanitize_file_name( $documentos_pdf['contrato'] );
            }
            if ( ! empty( $documentos_pdf['pagare'] ) ) {
                $comprobantes_guardados[] = 'contratos/' . sanitize_file_name( $documentos_pdf['pagare'] );
            }
        }
        $nombre_comprobante = implode( '|', array_filter( $comprobantes_guardados ) );
        $detalle_desembolso = 'Desembolso de crédito #' . $id . ' (' . $credito->tipo_credito . ')';

        $wpdb->insert(
            $wpdb->prefix . 'fondo_transacciones',
            array(
                'user_id' => $credito->user_id,
                'tipo' => 'desembolso_credito',
                'monto' => $credito_actualizado ? floatval( $credito_actualizado->monto_aprobado ) : floatval( $credito->monto_solicitado ),
                'estado' => 'aprobado',
                'detalle' => $detalle_desembolso,
                'comprobante_url' => $nombre_comprobante,
                'aprobado_por' => get_current_user_id(),
                'fecha_registro' => current_time( 'mysql' ),
                'fecha_aprobacion' => current_time( 'mysql' )
            ),
            array( '%d', '%s', '%f', '%s', '%s', '%s', '%d', '%s', '%s' )
        );

        wp_redirect(admin_url('admin.php?page=lud-tesoreria&msg=desembolsado'));
        exit;
    }

    /**
     * Calcula la fecha de la primera cuota según estatutos (día 5 del mes correspondiente).
     */
    private function calcular_fecha_primera_cuota( $fecha_inicio, $tipo_credito ) {
        try {
            $fecha = new DateTime( $fecha_inicio );
        } catch ( Exception $e ) {
            return current_time( 'Y-m-d' );
        }

        $meses = $tipo_credito === 'agil' ? 1 : 2;
        $fecha->modify( "+{$meses} months" );
        $fecha->setDate( $fecha->format( 'Y' ), $fecha->format( 'm' ), 5 );

        return $fecha->format( 'Y-m-d' );
    }

    /**
     * Calcula interés prorrateado por días para la primera cuota.
     */
    private function calcular_interes_prorrateado( $saldo, $tasa, $fecha_inicio, $fecha_vencimiento ) {
        try {
            $inicio = new DateTime( $fecha_inicio );
            $vencimiento = new DateTime( $fecha_vencimiento );
        } catch ( Exception $e ) {
            return round( $saldo * ( $tasa / 100 ), 2 );
        }

        $dias = (int) $inicio->diff( $vencimiento )->format( '%a' );
        if ( $dias <= 0 ) {
            return round( $saldo * ( $tasa / 100 ), 2 );
        }

        $factor_diario = ( $tasa / 100 ) / 30;
        return round( $saldo * $factor_diario * $dias, 2 );
    }

    /**
     * Genera la tabla de amortización para un crédito (corriente o ágil).
     */
    private function generar_tabla_amortizacion($credito_id) {
        global $wpdb;
        $tabla_amort = $wpdb->prefix . 'fondo_amortizacion';
        $credito = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}fondo_creditos WHERE id = $credito_id");
        $monto = floatval($credito->monto_solicitado);
        $plazo = intval($credito->plazo_meses);
        $tasa = floatval($credito->tasa_interes);
        
        $capital_mensual_base = round($monto / $plazo, 2);
        $suma_capitales = $capital_mensual_base * $plazo;
        $diferencia = $monto - $suma_capitales;
        $saldo = $monto;

        $fecha_inicio = $credito->fecha_aprobacion ?: $credito->fecha_solicitud;
        if ( ! $fecha_inicio ) {
            $fecha_inicio = current_time( 'mysql' );
        }
        $fecha_base = $this->calcular_fecha_primera_cuota( $fecha_inicio, $credito->tipo_credito );

        for ($i = 1; $i <= $plazo; $i++) {
            $fecha_vencimiento = $fecha_base;
            if ($i > 1) {
                $fecha = new DateTime( $fecha_base );
                $fecha->modify("+" . ($i - 1) . " months");
                $fecha->setDate($fecha->format('Y'), $fecha->format('m'), 5);
                $fecha_vencimiento = $fecha->format( 'Y-m-d' );
            }
            $capital_cuota = $capital_mensual_base;
            if ( $i == $plazo ) $capital_cuota += $diferencia;
            $interes_cuota = $i === 1
                ? $this->calcular_interes_prorrateado( $saldo, $tasa, $fecha_inicio, $fecha_vencimiento )
                : round( $saldo * ( $tasa / 100 ), 2 );
            $valor_cuota_total = $capital_cuota + $interes_cuota;

            $wpdb->insert( $tabla_amort, [
                'credito_id' => $credito_id, 'numero_cuota' => $i,
                'fecha_vencimiento' => $fecha_vencimiento,
                'capital_programado' => $capital_cuota,
                'interes_programado' => $interes_cuota,
                'valor_cuota_total' => $valor_cuota_total,
                'estado' => 'pendiente'
            ]);

            $saldo -= $capital_cuota;
        }
    }

    // =============================================================
    // CIERRE Y LIQUIDACIÓN ANUAL
    // =============================================================

    /**
     * Permite ejecutar el cierre mensual de forma manual desde el panel.
     */
    public function ejecutar_cierre_mensual_manual() {
        if (!current_user_can('manage_options')) wp_die('Sin permisos');
        // Este método queda por compatibilidad si se llama manual, 
        // pero la lógica real está en verificar_cierre_automatico
        $this->verificar_cierre_automatico();
        wp_redirect(admin_url('admin.php?page=lud-tesoreria&msg=cierre_ok'));
        exit;
    }

    /**
     * Liquida utilidades acumuladas en diciembre y registra los pagos.
     */
    public function procesar_liquidacion_anual() {
        if ( ! current_user_can( 'lud_manage_tesoreria' ) ) wp_die('Acceso denegado: No tienes permisos de Tesorero.');
        
        global $wpdb;
        $morosos = 0;
        $cuentas = $wpdb->get_results("SELECT user_id, fecha_ultimo_aporte FROM {$wpdb->prefix}fondo_cuentas WHERE estado_socio='activo'");
        $mes_corte = date('Y-m-01'); 
        foreach($cuentas as $c) { if ( $c->fecha_ultimo_aporte < $mes_corte ) $morosos++; }
        
        if ( $morosos > 0 ) wp_die("<div class='error'><h1>⛔ BLOQUEO</h1><p>Hay $morosos socios en mora.</p></div>");

        $anio = date('Y');
        
        // 1. Marcar como LIQUIDADO (Pagado en efectivo)
        $wpdb->query("UPDATE {$wpdb->prefix}fondo_utilidades_mensuales SET estado = 'liquidado' WHERE anio = $anio AND estado = 'provisional'");
        
        // Nota: NO sumamos al saldo porque el dinero se entrega físicamente.

        wp_redirect( admin_url('admin.php?page=lud-tesoreria&msg=liquidacion_exito') );
        exit;
    }

    // --- FUNCIÓN PUENTE PARA DEBUG TOOLS ---
    /**
     * Calcula la utilidad del mes actual sumando intereses y multas menos gastos.
     */
    public function calcular_utilidad_mes_actual() {
        // Para efectos de PRUEBAS y DEBUG, calculamos el mes ACTUAL.
        // La automatización real usa 'verificar_cierre_automatico' que sí mira el mes anterior.
        $mes = intval(date('m'));
        $anio = intval(date('Y'));
        
        $this->calcular_utilidad_mes_especifico($mes, $anio);
        
        // Si estamos en Enero, calculamos Diciembre del año anterior
        if ($mes == 1) { $mes = 12; $anio = $anio - 1; }
        else { $mes = $mes - 1; }

        $this->calcular_utilidad_mes_especifico($mes, $anio);
    }

    // Reemplaza la lógica inmediata por la lógica programada
    /**
     * Programa o aplica un cambio en el número de acciones de un socio.
     */
    public function procesar_actualizacion_acciones() {
        if ( ! current_user_can('lud_manage_tesoreria') ) wp_die('Sin permisos');
        check_admin_referer('lud_update_shares', 'security');
        
        global $wpdb;
        $user_id = intval($_POST['user_id']);
        $nuevas = intval($_POST['nuevas_acciones']);
        $motivo = sanitize_text_field($_POST['motivo_cambio']);

        // Comentario: límite duro de 10 acciones por estatutos.
        if ( $nuevas > 10 ) {
            wp_die( '<div style="padding:20px; font-family:sans-serif;"><h2>⚠️ Límite de acciones</h2><p>Un socio no puede tener más de 10 acciones.</p></div>' );
        }
        if ( $nuevas < 0 ) {
            $nuevas = 0;
        }
        
        // Calcular el 1 del próximo mes
        $fecha_efectiva = date('Y-m-01', strtotime('first day of next month'));
        
        // Guardar programación (NO actualizamos la tabla de cuentas todavía)
        update_user_meta($user_id, 'lud_acciones_programadas', [
            'cantidad' => $nuevas,
            'motivo' => $motivo,
            'fecha_efectiva' => $fecha_efectiva
        ]);
        
        // Redirigimos con mensaje de éxito "programado"
        wp_redirect( admin_url("admin.php?page=lud-tesoreria&view=detalle_socio&id=$user_id&msg=programado") );
        exit;
    }

    // Nueva función para cancelar si te equivocaste antes de que llegue el día 1
    /**
     * Cancela una programación de cambio de acciones para un socio.
     */
    public function procesar_cancelacion_acciones() {
        if ( ! current_user_can('lud_manage_tesoreria') ) wp_die('Sin permisos');
        check_admin_referer('lud_cancel_shares', 'security');
        
        $user_id = intval($_POST['user_id']);
        delete_user_meta($user_id, 'lud_acciones_programadas');

        wp_redirect( admin_url("admin.php?page=lud-tesoreria&view=detalle_socio&id=$user_id&msg=cancelado") );
        exit;
    }

    /**
     * Muestra el editor de ficha del socio dentro de tesorería.
     */
    private function render_editor_socio() {
        if ( !isset($_GET['id']) ) return;
        $user_id = intval($_GET['id']);
        
        // Seguridad y Datos
        if ( ! current_user_can('lud_manage_tesoreria') ) wp_die('Acceso denegado');
        global $wpdb;
        $user = get_userdata($user_id);
        $cuenta = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}fondo_cuentas WHERE user_id = $user_id");

        // Lógica de Bloqueo Temporal (1 año)
        $ultima_actualizacion = get_user_meta($user_id, 'lud_fecha_actualizacion_sensible', true);
        $bloqueo_sensible = false;
        $dias_restantes = 0;

        if ( $ultima_actualizacion ) {
            $fecha_limite = strtotime('+1 year', strtotime($ultima_actualizacion));
            if ( time() < $fecha_limite ) {
                $bloqueo_sensible = true;
                $dias_restantes = ceil(($fecha_limite - time()) / (60 * 60 * 24));
            }
        }
        
        // Helpers de UI
        $readonly_attr = $bloqueo_sensible ? 'readonly style="background:#eee; cursor:not-allowed;" title="Bloqueado por seguridad (1 vez al año)"' : '';
        $nota_bloqueo = $bloqueo_sensible ? "<div class='lud-alert error' style='margin-bottom:15px;'>🔒 <strong>Datos Sensibles Bloqueados:</strong> Se editaron hace menos de un año. Podrán modificarse nuevamente en $dias_restantes días.</div>" : "<div class='lud-alert success' style='margin-bottom:15px;'>🔓 <strong>Edición Habilitada:</strong> Puedes modificar datos sensibles (Nombre, Cédula, Fechas Clave). Al guardar, se bloquearán por 1 año.</div>";
        ?>

        <p><a href="?page=lud-tesoreria&view=detalle_socio&id=<?php echo $user_id; ?>" class="button">← Cancelar y Volver</a></p>
        
        <div class="lud-card" style="max-width:900px;">
            <h3>✏️ Editar Información del Asociado</h3>
            <?php echo $nota_bloqueo; ?>

            <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="lud_guardar_edicion_socio">
                <input type="hidden" name="user_id" value="<?php echo $user_id; ?>">
                <?php wp_nonce_field('lud_edit_user_'.$user_id, 'security'); ?>

                <h4 style="border-bottom:1px solid #eee; padding-bottom:5px; margin-top:20px;">1. Datos Personales (Sensibles)</h4>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px;">
                    <div>
                        <label class="lud-label">Nombre Completo</label>
                        <input type="text" name="nombre_completo" class="lud-input" value="<?php echo esc_attr($user->display_name); ?>" <?php echo $readonly_attr; ?>>
                    </div>
                    <div>
                        <label class="lud-label">Fecha de Nacimiento</label>
                        <input type="date" name="fecha_nacimiento" class="lud-input" value="<?php echo esc_attr($cuenta->fecha_nacimiento); ?>" <?php echo $readonly_attr; ?>>
                    </div>
                    <div>
                        <label class="lud-label">Tipo Documento</label>
                        <select name="tipo_documento" class="lud-input" <?php if($bloqueo_sensible) echo 'style="pointer-events:none; background:#eee;" readonly'; ?>>
                            <option value="CC" <?php selected($cuenta->tipo_documento, 'CC'); ?>>C.C.</option>
                            <option value="CE" <?php selected($cuenta->tipo_documento, 'CE'); ?>>C.E.</option>
                            <option value="Pasaporte" <?php selected($cuenta->tipo_documento, 'Pasaporte'); ?>>Pasaporte</option>
                        </select>
                    </div>
                    <div>
                        <label class="lud-label">Número Documento</label>
                        <input type="text" name="numero_documento" class="lud-input" value="<?php echo esc_attr($cuenta->numero_documento); ?>" <?php echo $readonly_attr; ?>>
                    </div>
                </div>

                <h4 style="border-bottom:1px solid #eee; padding-bottom:5px; margin-top:20px;">2. Información de Contacto (Editable)</h4>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px;">
                    <div>
                        <label class="lud-label">Dirección Residencia</label>
                        <input type="text" name="direccion" class="lud-input" value="<?php echo esc_attr($cuenta->direccion_residencia); ?>" required>
                    </div>
                    <div>
                        <label class="lud-label">Ciudad y País</label>
                        <input type="text" name="ciudad" class="lud-input" value="<?php echo esc_attr($cuenta->ciudad_pais); ?>" required>
                    </div>
                    <div>
                        <label class="lud-label">Teléfono</label>
                        <input type="text" name="telefono" class="lud-input" value="<?php echo esc_attr($cuenta->telefono_contacto); ?>" required>
                    </div>
                    <div>
                        <label class="lud-label">Correo Electrónico</label>
                        <input type="email" name="email" class="lud-input" value="<?php echo esc_attr($user->user_email); ?>" required>
                    </div>
                </div>

                <h4 style="border-bottom:1px solid #eee; padding-bottom:5px; margin-top:20px;">3. Datos Históricos del Fondo (Sensibles)</h4>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px;">
                    <div>
                        <label class="lud-label">Fecha de Ingreso</label>
                        <input type="date" name="fecha_ingreso" class="lud-input" value="<?php echo esc_attr($cuenta->fecha_ingreso_fondo); ?>" <?php echo $readonly_attr; ?>>
                    </div>
                    <div>
                        <label class="lud-label">Aporte Inicial Histórico ($)</label>
                        <input type="number" name="aporte_inicial" class="lud-input" value="<?php echo esc_attr($cuenta->aporte_inicial); ?>" <?php echo $readonly_attr; ?>>
                    </div>
                </div>

                <h4 style="border-bottom:1px solid #eee; padding-bottom:5px; margin-top:20px;">4. Perfil Financiero (Editable)</h4>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px;">
                    <div>
                        <label class="lud-label">Actividad Económica</label>
                        <input type="text" name="actividad" class="lud-input" value="<?php echo esc_attr($cuenta->actividad_economica); ?>">
                    </div>
                    <div>
                        <label class="lud-label">Fuente de Ingresos</label>
                        <input type="text" name="origen" class="lud-input" value="<?php echo esc_attr($cuenta->origen_fondos); ?>">
                    </div>
                    <div style="grid-column: span 2;">
                        <label class="lud-label">Banco / Medio de Pago Habitual</label>
                        <input type="text" name="banco" class="lud-input" value="<?php echo esc_attr($cuenta->banco_medio_pago); ?>">
                    </div>
                </div>

                <h4 style="border-bottom:1px solid #eee; padding-bottom:5px; margin-top:20px;">5. Actualizar Documento</h4>
                <div class="lud-form-group">
                    <label class="lud-label">Nuevo PDF Documento Identidad (Opcional)</label>
                    <?php if($cuenta->url_documento_id): ?>
                        <small style="display:block; margin-bottom:5px;">Actual: <a href="<?php echo admin_url('admin-post.php?action=lud_ver_comprobante&file=documentos/'.$cuenta->url_documento_id); ?>" target="_blank">Ver Documento</a></small>
                    <?php endif; ?>
                    <input type="file" name="archivo_documento" class="lud-input" accept="application/pdf">
                </div>

                <div style="margin-top:30px; text-align:right;">
                    <button type="submit" class="button button-primary button-hero">💾 Guardar Cambios y Registrar Log</button>
                </div>
            </form>
        </div>
        <?php
    }

    // --- PROCESAMIENTO DEL EDITOR ---
    /**
     * Guarda los cambios hechos en la ficha del socio desde el panel.
     */
    public function procesar_edicion_socio() {
        if ( ! current_user_can('lud_manage_tesoreria') ) wp_die('Sin permisos');
        $user_id = intval($_POST['user_id']);
        check_admin_referer('lud_edit_user_'.$user_id, 'security');

        global $wpdb;
        $user = get_userdata($user_id);
        $cuenta = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}fondo_cuentas WHERE user_id = $user_id");

        // 1. Verificar Bloqueo Sensible
        $ultima_actualizacion = get_user_meta($user_id, 'lud_fecha_actualizacion_sensible', true);
        $bloqueado = false;
        if ( $ultima_actualizacion && (time() < strtotime('+1 year', strtotime($ultima_actualizacion))) ) {
            $bloqueado = true;
        }

        $log_cambios = [];
        $datos_cuenta_update = [];
        $datos_user_update = [];

        // --- DEFINICIÓN DE CAMPOS ---
        // Campo POST => [Campo DB, Nombre Legible, Es Sensible?]
        $mapa_campos = [
            'nombre_completo'  => ['display_name', 'Nombre', true],
            'fecha_nacimiento' => ['fecha_nacimiento', 'F. Nacimiento', true],
            'tipo_documento'   => ['tipo_documento', 'Tipo Doc', true],
            'numero_documento' => ['numero_documento', 'Num Doc', true],
            'fecha_ingreso'    => ['fecha_ingreso_fondo', 'F. Ingreso', true],
            'aporte_inicial'   => ['aporte_inicial', 'Aporte Inicial', true],
            
            'direccion'        => ['direccion_residencia', 'Dirección', false],
            'ciudad'           => ['ciudad_pais', 'Ciudad', false],
            'telefono'         => ['telefono_contacto', 'Teléfono', false],
            'email'            => ['user_email', 'Email', false],
            'actividad'        => ['actividad_economica', 'Actividad', false],
            'origen'           => ['origen_fondos', 'Origen Fondos', false],
            'banco'            => ['banco_medio_pago', 'Banco', false]
        ];

        // 2. Iterar y Detectar Cambios
        $hubo_cambio_sensible = false;

        foreach ($mapa_campos as $post_key => $config) {
            $db_field = $config[0];
            $label = $config[1];
            $es_sensible = $config[2];

            // Si está bloqueado y es sensible, ignoramos el input del formulario
            if ( $bloqueado && $es_sensible ) continue;

            $nuevo_valor = sanitize_text_field($_POST[$post_key]);
            
            // Obtener valor actual
            $valor_actual = '';
            if ( $db_field == 'display_name' || $db_field == 'user_email' ) {
                $valor_actual = $user->$db_field;
            } else {
                $valor_actual = $cuenta->$db_field;
            }

            // Comparar (Uso != para no estricto tipos)
            if ( $valor_actual != $nuevo_valor ) {
                $log_cambios[] = "$label: '$valor_actual' -> '$nuevo_valor'";
                
                if ( $db_field == 'display_name' || $db_field == 'user_email' ) {
                    $datos_user_update['ID'] = $user_id;
                    $datos_user_update[$db_field] = $nuevo_valor;
                } else {
                    $datos_cuenta_update[$db_field] = $nuevo_valor;
                }

                if ( $es_sensible ) $hubo_cambio_sensible = true;
            }
        }

        // 3. Procesar Archivo (Siempre editable)
        if ( !empty($_FILES['archivo_documento']['name']) ) {
            $file = $_FILES['archivo_documento'];
            if ( $file['type'] == 'application/pdf' ) {
                $upload_dir = wp_upload_dir();
                $target_dir = $upload_dir['basedir'] . '/fondo_seguro/documentos/';
                $filename = 'doc_update_' . $user_id . '_' . time() . '.pdf';
                move_uploaded_file( $file['tmp_name'], $target_dir . $filename );
                
                $datos_cuenta_update['url_documento_id'] = $filename;
                $log_cambios[] = "Documento ID: Actualizado";
            }
        }

        // 4. Ejecutar Actualizaciones
        if ( !empty($datos_user_update) ) wp_update_user($datos_user_update);
        if ( !empty($datos_cuenta_update) ) $wpdb->update("{$wpdb->prefix}fondo_cuentas", $datos_cuenta_update, ['user_id' => $user_id]);

        // 5. Registrar Log y Bloqueo
        if ( !empty($log_cambios) ) {
            // Si hubo cambio sensible, activamos el timer de 1 año
            if ( $hubo_cambio_sensible ) {
                update_user_meta($user_id, 'lud_fecha_actualizacion_sensible', current_time('mysql'));
                $log_cambios[] = "[BLOQUEO ACTIVADO POR 1 AÑO]";
            }

            $detalle_log = "Actualización de datos (" . wp_get_current_user()->display_name . "): " . implode(', ', $log_cambios);
            
            $wpdb->insert("{$wpdb->prefix}fondo_transacciones", [
                'user_id' => $user_id,
                'tipo' => 'actualizacion_datos',
                'monto' => 0,
                'estado' => 'aprobado',
                'detalle' => $detalle_log,
                'aprobado_por' => get_current_user_id(),
                'fecha_registro' => current_time('mysql'),
                'fecha_aprobacion' => current_time('mysql')
            ]);

            // Registrar notificación de actualización de datos.
            update_user_meta( $user_id, 'lud_ultima_actualizacion_datos', current_time('mysql') );
            do_action( 'lud_evento_datos_actualizados', $user_id, 'Panel administrativo', implode(', ', $log_cambios) );
        }

        wp_redirect( admin_url("admin.php?page=lud-tesoreria&view=detalle_socio&id=$user_id&msg=datos_actualizados") );
        exit;
    }

    /**
     * Procesa la marcación de asistencia y genera multas para ausentes.
     */
    public function procesar_guardado_asistencia() {
        if ( ! current_user_can( 'lud_manage_tesoreria' ) ) wp_die( 'Sin permisos' );
        check_admin_referer( 'lud_asistencia_nonce', 'security' );

        global $wpdb;
        $fecha_asamblea = ! empty( $_POST['fecha_asamblea'] ) ? sanitize_text_field( $_POST['fecha_asamblea'] ) : date( 'Y-m-d' );
        $socios_presentes = isset( $_POST['socio_presente'] ) ? array_map( 'intval', $_POST['socio_presente'] ) : array();

        $socios_activos = $wpdb->get_col( "SELECT user_id FROM {$wpdb->prefix}fondo_cuentas WHERE estado_socio = 'activo'" );

        foreach ( $socios_activos as $socio_id ) {
            if ( in_array( $socio_id, $socios_presentes ) ) {
                continue; // Comentario: asistió, no se genera multa.
            }

            if ( $this->existe_multa_inasistencia( $socio_id, $fecha_asamblea ) ) {
                continue; // Comentario: ya existe multa registrada para esa fecha.
            }

            $wpdb->insert(
                $wpdb->prefix . 'fondo_transacciones',
                array(
                    'user_id' => $socio_id,
                    'tipo' => 'multa',
                    'monto' => 10000,
                    'metodo_pago' => 'pendiente_cobro',
                    'estado' => 'pendiente',
                    'detalle' => 'Inasistencia Asamblea (' . $fecha_asamblea . ')',
                    'fecha_registro' => current_time( 'mysql' )
                )
            );
        }

        wp_redirect( admin_url( 'admin.php?page=lud-tesoreria&view=control_asistencia&msg=asistencia_guardada' ) );
        exit;
    }

    /**
     * Aprueba una solicitud de ingreso y la marca como activa.
     */
    public function procesar_aprobacion_registro() {
        if ( ! $this->usuario_es_presidencia() ) wp_die( 'Sin permisos' );
        $cuenta_id = intval( $_POST['cuenta_id'] ?? 0 );
        check_admin_referer( 'aprobar_socio_' . $cuenta_id, 'security' );

        global $wpdb;
        $cuenta = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}fondo_cuentas WHERE id = %d", $cuenta_id ) );
        if ( ! $cuenta ) {
            wp_redirect( admin_url( 'admin.php?page=lud-tesoreria&view=presidencia&msg=admision_err' ) ); exit;
        }

        $wpdb->update(
            "{$wpdb->prefix}fondo_cuentas",
            array(
                'estado_socio' => 'activo',
                'fecha_ingreso_fondo' => $cuenta->fecha_ingreso_fondo ? $cuenta->fecha_ingreso_fondo : date( 'Y-m-d' )
            ),
            array( 'id' => $cuenta_id )
        );

        // Comentario: registrar bitácora en transacciones.
        $wpdb->insert(
            "{$wpdb->prefix}fondo_transacciones",
            array(
                'user_id' => $cuenta->user_id,
                'tipo' => 'aporte',
                'monto' => 0,
                'metodo_pago' => 'admision',
                'estado' => 'aprobado',
                'detalle' => 'ADMISION: Aprobado por Presidencia',
                'aprobado_por' => get_current_user_id(),
                'fecha_registro' => current_time( 'mysql' ),
                'fecha_aprobacion' => current_time( 'mysql' )
            )
        );

        wp_redirect( admin_url( 'admin.php?page=lud-tesoreria&view=presidencia&msg=admision_ok' ) );
        exit;
    }

    /**
     * Rechaza una solicitud de ingreso exigiendo motivo y notifica al solicitante.
     */
    public function procesar_rechazo_registro() {
        if ( ! $this->usuario_es_presidencia() ) wp_die( 'Sin permisos' );
        $cuenta_id = intval( $_POST['cuenta_id'] ?? 0 );
        $motivo = sanitize_textarea_field( $_POST['motivo_rechazo'] ?? '' );
        if ( empty( $motivo ) ) {
            wp_redirect( admin_url( 'admin.php?page=lud-tesoreria&view=presidencia&msg=admision_err' ) ); exit;
        }
        check_admin_referer( 'rechazar_socio_' . $cuenta_id, 'security' );

        global $wpdb;
        $cuenta = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}fondo_cuentas WHERE id = %d", $cuenta_id ) );
        if ( ! $cuenta ) {
            wp_redirect( admin_url( 'admin.php?page=lud-tesoreria&view=presidencia&msg=admision_err' ) ); exit;
        }

        $wpdb->update(
            "{$wpdb->prefix}fondo_cuentas",
            array( 'estado_socio' => 'rechazado' ),
            array( 'id' => $cuenta_id )
        );

        $detalle = 'ADMISION: Rechazado por Presidencia. Motivo: ' . $motivo;
        $wpdb->insert(
            "{$wpdb->prefix}fondo_transacciones",
            array(
                'user_id' => $cuenta->user_id,
                'tipo' => 'aporte',
                'monto' => 0,
                'metodo_pago' => 'admision',
                'estado' => 'rechazado',
                'detalle' => $detalle,
                'aprobado_por' => get_current_user_id(),
                'fecha_registro' => current_time( 'mysql' ),
                'fecha_aprobacion' => current_time( 'mysql' )
            )
        );

        // Comentario: notificar por correo al solicitante con el motivo.
        $usuario = get_userdata( $cuenta->user_id );
        if ( $usuario && ! empty( $usuario->user_email ) ) {
            $asunto = 'Resultado de tu solicitud de ingreso al Fondo La Unión';
            $mensaje = "Hola {$usuario->display_name},\n\n"
                     . "Tu solicitud fue rechazada.\n"
                     . "Motivo: {$motivo}\n\n"
                     . "Si necesitas más información, comunícate con Presidencia.";
            wp_mail( $usuario->user_email, $asunto, $mensaje );
        }

        wp_redirect( admin_url( 'admin.php?page=lud-tesoreria&view=presidencia&msg=admision_ok' ) );
        exit;
    }

    /**
     * Verifica si ya existe una multa de inasistencia para la fecha indicada.
     */
    private function existe_multa_inasistencia( $user_id, $fecha_asamblea ) {
        global $wpdb;
        $like = '%' . $wpdb->esc_like( 'Inasistencia Asamblea (' . $fecha_asamblea . ')' ) . '%';
        $conteo = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fondo_transacciones WHERE user_id = %d AND tipo = 'multa' AND detalle LIKE %s",
            $user_id,
            $like
        ) );

        return $conteo > 0;
    }

    /**
     * Registra la entrega física de la caja de secretaría.
     */
    public function procesar_entrega_secretaria() {
        if ( ! current_user_can( 'lud_manage_tesoreria' ) ) wp_die('Sin permisos');
        check_admin_referer('lud_pay_sec', 'security');
        
        global $wpdb;
        $monto = floatval($_POST['monto']);
        $user_id = get_current_user_id();

        if ($monto <= 0) wp_die('Monto inválido');

        // Insertar como GASTO OPERATIVO para que descuente de la caja física 
        // Categoría 'secretaria' es clave para que la card sepa que ya se pagó.
        $wpdb->insert(
            $wpdb->prefix . 'fondo_gastos',
            array(
                'categoria' => 'secretaria',
                'descripcion' => 'Entrega de recaudo mensual a Secretaría (Caja Menor)',
                'monto' => $monto,
                'registrado_por' => $user_id,
                'fecha_gasto' => current_time('mysql')
            )
        );

        // Opcional: Registrar log en transacciones para auditoría
        $wpdb->insert($wpdb->prefix . 'fondo_transacciones', [
            'user_id' => $user_id, 'tipo' => 'gasto_operativo', 'monto' => $monto, 
            'estado' => 'aprobado', 'aprobado_por' => $user_id,
            'detalle' => 'SISTEMA: Legalización entrega dinero a Secretaría'
        ]);

        wp_redirect( admin_url('admin.php?page=lud-tesoreria&msg=sec_paid') );
        exit;
    }

    /**
     * Procesa la aprobación o rechazo de un retiro voluntario.
     */
    public function procesar_respuesta_retiro() {
        if ( ! current_user_can( 'lud_manage_tesoreria' ) ) wp_die('Sin permisos');

        $retiro_id = intval( $_POST['retiro_id'] );
        $decision = sanitize_text_field( $_POST['decision'] );
        $motivo = isset( $_POST['motivo'] ) ? sanitize_textarea_field( $_POST['motivo'] ) : '';

        check_admin_referer( 'lud_responder_retiro_' . $retiro_id, 'security' );

        if ( $decision !== 'aprobado' && $decision !== 'rechazado' ) {
            wp_die( 'Decisión inválida' );
        }

        if ( $decision === 'rechazado' && empty( $motivo ) ) {
            wp_die( 'Debes indicar el motivo de rechazo.' );
        }

        global $wpdb;
        $tabla = "{$wpdb->prefix}fondo_retiros";

        $wpdb->update(
            $tabla,
            array(
                'estado'           => $decision,
                'fecha_respuesta'  => current_time( 'mysql' ),
                'usuario_respuesta'=> get_current_user_id(),
                'motivo_respuesta' => $motivo
            ),
            array( 'id' => $retiro_id ),
            array( '%s', '%s', '%d', '%s' ),
            array( '%d' )
        );

        // Notificar al socio la decisión sobre su retiro.
        $retiro = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}fondo_retiros WHERE id = %d", $retiro_id ) );
        if ( $retiro ) {
            do_action( 'lud_evento_retiro', $retiro->user_id, $decision, $retiro->monto_estimado, $motivo );
        }

        $redirect = admin_url( 'admin.php?page=lud-tesoreria&view=dashboard&lud_msg=retiro_' . $decision );
        wp_redirect( $redirect );
        exit;
    }

    /**
     * Guarda la configuración de correos desde la pestaña de ajustes.
     */
    public function procesar_config_correos() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Sin permisos' );
        if ( ! isset( $_POST['lud_seguridad'] ) || ! wp_verify_nonce( $_POST['lud_seguridad'], 'lud_config_correo' ) ) wp_die( 'Seguridad' );

        $config = array(
            'logo_url'                   => esc_url_raw( $_POST['logo_url'] ),
            'url_portal'                 => esc_url_raw( $_POST['url_portal'] ),
            'enlace_politicas'           => esc_url_raw( $_POST['enlace_politicas'] ),
            'enlace_actualizacion_datos' => esc_url_raw( $_POST['enlace_actualizacion_datos'] ),
            'remitente_nombre'           => sanitize_text_field( $_POST['remitente_nombre'] ),
            'texto_pie'                  => sanitize_textarea_field( $_POST['texto_pie'] )
        );

        lud_notificaciones()->guardar_configuracion( $config );
        wp_redirect( admin_url( 'admin.php?page=lud-tesoreria&view=configuracion_fondo&conf=ok' ) );
        exit;
    }

    /**
     * Envía un correo de prueba usando la plantilla configurada.
     */
    public function procesar_test_correo() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Sin permisos' );
        if ( ! isset( $_POST['lud_seguridad'] ) || ! wp_verify_nonce( $_POST['lud_seguridad'], 'lud_test_correo' ) ) wp_die( 'Seguridad' );

        $destino = sanitize_email( $_POST['correo_destino'] );
        if ( ! is_email( $destino ) ) wp_die( 'Correo inválido' );

        lud_notificaciones()->enviar_correo_prueba( $destino );
        wp_redirect( admin_url( 'admin.php?page=lud-tesoreria&view=configuracion_fondo&conf=test' ) );
        exit;
    }

    /**
     * Descompone múltiples comprobantes guardados en una sola columna.
     */
    private function dividir_comprobantes_multiples( $comprobante_url ) {
        $partes = array_filter( array_map( 'trim', explode( '|', (string) $comprobante_url ) ) );
        $rutas_limpias = array();

        foreach ( $partes as $parte ) {
            $segmentos = array_filter( array_map( 'sanitize_file_name', explode( '/', $parte ) ) );
            if ( empty( $segmentos ) ) {
                continue;
            }
            $rutas_limpias[] = implode( '/', $segmentos );
        }

        return $rutas_limpias;
    }

    /**
     * Construye enlaces HTML seguros para uno o varios comprobantes.
     */
    private function construir_enlaces_comprobantes( $comprobante_url, $texto_unico = 'Ver archivo' ) {
        $rutas = $this->dividir_comprobantes_multiples( $comprobante_url );
        if ( empty( $rutas ) ) {
            return '-';
        }

        $enlaces = array();
        foreach ( $rutas as $ruta ) {
            $etiqueta = $texto_unico;
            if ( stripos( $ruta, 'pagare' ) !== false ) {
                $etiqueta = 'Ver pagaré';
            } elseif ( stripos( $ruta, 'contrato' ) !== false ) {
                $etiqueta = 'Ver contrato';
            }

            $url = admin_url( 'admin-post.php?action=lud_ver_comprobante&file=' . rawurlencode( $ruta ) );
            $enlaces[] = '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $etiqueta ) . '</a>';
        }

        return implode( '<br>', $enlaces );
    }

} // FIN DE LA CLASE
}
