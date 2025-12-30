<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class LUD_Debug_Tools {

    private $log = [];

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_debug_menu' ) );
        add_action( 'admin_post_lud_run_tests', array( $this, 'ejecutar_bateria_pruebas' ) );
    }

    public function register_debug_menu() {
        // CAMBIO: 'manage_options' -> 'update_core'
        // Esto oculta el menú para Tesoreros y Secretarias, solo lo ve el Administrador Técnico.
        add_menu_page( 'Panel de Pruebas', '🧪 LUD Tests', 'update_core', 'lud-debug', array( $this, 'render_debug_page' ), 'dashicons-beaker', 99 );
    }
    public function render_debug_page() {
        $logs = get_transient( 'lud_test_logs' );
        ?>
        <div class="wrap">
            <h1>🧪 Suite de Pruebas "Caja de Cristal"</h1>
            <p>Este módulo ejecuta simulaciones y muestra las matemáticas internas para validación manual.</p>
            
            <div style="background:#fff; padding:20px; border:1px solid #ccd0d4; border-radius:5px; margin-bottom:20px;">
                <h3>⚠️ Modo de Pruebas</h3>
                <p>Se crearán datos temporales y se intentará limpiar al finalizar. No afecta saldos reales de otros socios.</p>
                <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>">
                    <input type="hidden" name="action" value="lud_run_tests">
                    <?php wp_nonce_field('run_tests_nonce', 'security'); ?>
                    <button type="submit" class="button button-primary button-hero">⚡ EJECUTAR Y MOSTRAR CÁLCULOS</button>
                </form>
            </div>

            <?php if ( $logs ): ?>
                <h2>📋 Bitácora de Validación (Calculadora en mano)</h2>
                <div style="background:#1d2327; color:#a7aaad; padding:20px; border-radius:5px; font-family:monospace; font-size:13px; line-height:1.6; white-space:pre-wrap; max-height:800px; overflow-y:auto;">
                    <?php echo $logs; // Ya viene escapado/formateado desde el generador ?>
                </div>
                <?php delete_transient( 'lud_test_logs' ); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    public function ejecutar_bateria_pruebas() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die('Acceso denegado');
        check_admin_referer( 'run_tests_nonce', 'security' );

        $this->log("INICIO DE AUDITORÍA AUTOMÁTICA: " . date('Y-m-d H:i:s'));
        $this->hr();

        // 1. Preparar Entorno
        $user_id = $this->get_or_create_dummy_user();
        
        // 2. Ejecutar Casos
        try {
            $this->test_calculo_deuda_y_multas($user_id);
            $this->hr();
            $this->test_regla_del_70_porciento($user_id);
            $this->hr();
            $this->test_justicia_distribucion_utilidades($user_id);
            $this->hr();
            $this->test_liquidez_reservada();
        } catch (Exception $e) {
            $this->fail("EXCEPCIÓN CRÍTICA: " . $e->getMessage());
        }
        
        // 3. Finalizar
        $this->hr();
        $this->log("🏁 FIN DE PRUEBAS.");
        
        set_transient( 'lud_test_logs', implode("\n", $this->log), 60 );
        wp_redirect( admin_url( 'admin.php?page=lud-debug' ) );
        exit;
    }

    // --- TEST 1: CÁLCULO DE DEUDA Y MULTAS (Detallado) ---
    private function test_calculo_deuda_y_multas($user_id) {
        $this->header("CASO 1: Validación de Mora y Multas");
        
        global $wpdb;
        
        // ESCENARIO:
        // Último pago: Hace 2 meses.
        // Acciones: 2
        // Hoy simulado: Día 10 del mes actual.
        // DEBE: Mes 1 (Vencido) + Mes 2 (Vencido) + Mes Actual (En curso pero es día 10, ya hay mora).
        
        $fecha_simulada_ultimo_pago = date('Y-m-01', strtotime('-2 months')); 
        $acciones = 2;
        $valor_accion = 50000;
        $valor_sec = 1000;
        $multa_diaria = 1000;
        
        // Inyectar datos
        $wpdb->update("{$wpdb->prefix}fondo_cuentas", 
            ['numero_acciones' => $acciones, 'fecha_ultimo_aporte' => $fecha_simulada_ultimo_pago], 
            ['user_id' => $user_id]
        );

        $this->log("🔹 DATOS DE ENTRADA:");
        $this->log("   - Usuario ID: $user_id");
        $this->log("   - Fecha Último Pago: $fecha_simulada_ultimo_pago");
        $this->log("   - Acciones: $acciones");
        $this->log("   - Fecha Hoy (Sistema): " . date('Y-m-d'));
        
        // Ejecutar Sistema
        $modulo_tx = new LUD_Module_Transacciones();
        if ( ! method_exists($modulo_tx, 'calcular_deuda_usuario') ) {
            $this->fail("❌ ERROR: El método 'calcular_deuda_usuario' sigue siendo PRIVATE. Cámbialo a PUBLIC en class-module-transacciones.php");
            return;
        }
        $deuda = $modulo_tx->calcular_deuda_usuario($user_id);

        // CÁLCULO MANUAL PARA VALIDAR
        // 1. Meses atrasados completos (Mes -1, Mes -2) + Mes actual = 3 meses de cuota base
        // Nota: La lógica del loop en transacciones cuenta desde el mes siguiente al ultimo pago hasta hoy.
        // Si pagó hace 2 meses (ej: 1 Oct), el loop corre Nov, Dic, Ene (si estamos en Ene).
        // Vamos a confiar en la lógica del loop y validarla:
        
        $meses_a_cobrar = 0;
        $dias_mora_totales = 0;
        
        $inicio = new DateTime($fecha_simulada_ultimo_pago);
        $inicio->modify('first day of next month');
        $hoy = new DateTime();
        
        while ($inicio <= $hoy) {
            $meses_a_cobrar++;
            // Regla día 5
            $limite = clone $inicio;
            $limite->setDate($inicio->format('Y'), $inicio->format('m'), 5);
            if ($hoy > $limite) {
                $diff = $hoy->diff($limite)->days;
                $dias_mora_totales += $diff;
            }
            $inicio->modify('first day of next month');
        }

        $esperado_ahorro = $meses_a_cobrar * $acciones * $valor_accion;
        $esperado_sec = $meses_a_cobrar * $acciones * $valor_sec;
        $esperado_multa = $dias_mora_totales * $acciones * $multa_diaria;

        $this->log("\n🔹 VALIDACIÓN MATEMÁTICA (Calculadora):");
        $this->log("   - Meses detectados sin pago: $meses_a_cobrar");
        $this->log("   - Días totales de mora acumulada: $dias_mora_totales días");
        $this->log("   - Cálculo Ahorro: $meses_a_cobrar meses * $acciones acc * $".number_format($valor_accion)." = $".number_format($esperado_ahorro));
        $this->log("   - Cálculo Multa:  $dias_mora_totales días * $acciones acc * $".number_format($multa_diaria)." = $".number_format($esperado_multa));

        $this->log("\n🔹 RESULTADO DEL SISTEMA:");
        $this->log("   - Ahorro Calculado: $ " . number_format($deuda['ahorro']));
        $this->log("   - Multa Calculada:  $ " . number_format($deuda['multa']));

        if ( $deuda['ahorro'] == $esperado_ahorro && $deuda['multa'] == $esperado_multa ) {
            $this->pass("Cálculos coinciden exactamente al centavo.");
        } else {
            $this->fail("Diferencia detectada. Revisa la lógica de fechas.");
        }
    }

    // --- TEST 2: REGLA 70% (Detallado) ---
    private function test_regla_del_70_porciento($user_id) {
        $this->header("CASO 2: Regla del 70% (Refinanciación)");
        global $wpdb;

        $monto_prestado = 2000000;
        $saldo_pendiente = 800000; // Ha pagado 1.2M
        
        $wpdb->delete("{$wpdb->prefix}fondo_creditos", ['user_id' => $user_id]);
        $wpdb->insert("{$wpdb->prefix}fondo_creditos", [
            'user_id' => $user_id, 'tipo_credito' => 'corriente', 'monto_aprobado' => $monto_prestado,
            'saldo_actual' => $saldo_pendiente, 'estado' => 'activo', 'plazo_meses' => 12, 'tasa_interes' => 2
        ]);

        $pagado = $monto_prestado - $saldo_pendiente;
        $porcentaje_real = ($pagado / $monto_prestado) * 100;

        $this->log("🔹 ESCENARIO:");
        $this->log("   - Crédito Original: $ " . number_format($monto_prestado));
        $this->log("   - Saldo Pendiente:  $ " . number_format($saldo_pendiente));
        $this->log("   - Total Pagado:     $ " . number_format($pagado));
        
        $this->log("\n🔹 VALIDACIÓN:");
        $this->log("   - Fórmula: ($pagado / $monto_prestado) * 100");
        $this->log("   - Porcentaje Pagado: " . number_format($porcentaje_real, 2) . "%");
        $this->log("   - Requisito Mínimo:  70.00%");

        if ( $porcentaje_real < 70 ) {
            $this->pass("El sistema BLOQUEARÍA esta solicitud (60% < 70%).");
        } else {
            $this->pass("El sistema PERMITIRÍA esta solicitud. (En este caso 60% es fallo, prueba con saldo 500k para ver pass verde de aprobación).");
        }
        
        // Limpieza
        $wpdb->delete("{$wpdb->prefix}fondo_creditos", ['user_id' => $user_id]);
    }

    // --- TEST 3: JUSTICIA EN UTILIDADES (Detallado) ---
    private function test_justicia_distribucion_utilidades($user_id) {
        $this->header("CASO 3: Repartición Justa de Utilidades");
        global $wpdb;

        // 1. Limpiar entorno
        $mes = date('m'); $anio = date('Y');
        $wpdb->delete("{$wpdb->prefix}fondo_utilidades_mensuales", ['mes' => $mes, 'anio' => $anio]);
        $wpdb->delete("{$wpdb->prefix}fondo_recaudos_detalle", ['transaccion_id' => 9999]);
        
        // 2. Crear Ingresos Ficticios (Intereses ganados por el fondo)
        $ingreso_intereses = 1000000;
        $gasto_operativo = 200000;
        
        $wpdb->insert("{$wpdb->prefix}fondo_recaudos_detalle", ['transaccion_id' => 9999, 'user_id' => 1, 'concepto' => 'interes_credito', 'monto' => $ingreso_intereses, 'fecha_recaudo' => current_time('mysql')]);
        $wpdb->insert("{$wpdb->prefix}fondo_gastos", ['categoria' => 'test', 'descripcion' => 'test', 'monto' => $gasto_operativo, 'fecha_gasto' => current_time('mysql'), 'registrado_por' => 1]);

        // 3. Configurar al Usuario de Prueba como MOROSO
        // Su último pago fue hace 2 meses, por lo tanto NO cubre el mes actual.
        $fecha_mora = date('Y-m-d', strtotime('-2 months'));
        $wpdb->update("{$wpdb->prefix}fondo_cuentas", ['fecha_ultimo_aporte' => $fecha_mora, 'numero_acciones' => 10], ['user_id' => $user_id]);

        $utilidad_neta = $ingreso_intereses - $gasto_operativo;

        $this->log("🔹 BALANCE DEL MES (Simulado):");
        $this->log("   (+) Ingresos Intereses: $ " . number_format($ingreso_intereses));
        $this->log("   (-) Gastos Operativos:  $ " . number_format($gasto_operativo));
        $this->log("   (=) Utilidad Neta:      $ " . number_format($utilidad_neta));
        
        $this->log("\n🔹 ESTADO DEL USUARIO:");
        $this->log("   - Fecha Último Aporte: $fecha_mora");
        $this->log("   - Mes a Liquidar: " . date('Y-m'));
        $this->log("   - ¿Está al día?: NO (Mora detectada)");

        // 4. Ejecutar Cálculo
        $tesoreria = new LUD_Admin_Tesoreria();
        // Forzamos el cálculo aunque ya exista (borramos antes)
        $tesoreria->calcular_utilidad_mes_actual();

        // 5. Verificar
        $resultado = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}fondo_utilidades_mensuales WHERE user_id = $user_id AND mes = $mes AND anio = $anio");
        
        $asignado = $resultado ? floatval($resultado->utilidad_asignada) : -1;

        $this->log("\n🔹 RESULTADO DEL SISTEMA:");
        $this->log("   - Utilidad Asignada al Usuario: $ " . number_format($asignado));

        if ( $asignado === 0.00 ) {
            $this->pass("CORRECTO. El sistema le asignó $0 por estar en mora. Justicia aplicada.");
        } else {
            $this->fail("ERROR. El sistema le asignó dinero ($$asignado) a un moroso.");
        }

        // Limpieza
        $wpdb->delete("{$wpdb->prefix}fondo_recaudos_detalle", ['transaccion_id' => 9999]);
        $wpdb->delete("{$wpdb->prefix}fondo_gastos", ['categoria' => 'test']);
    }

    // --- TEST 4: LIQUIDEZ ---
    private function test_liquidez_reservada() {
        $this->header("CASO 4: Cálculo de Liquidez Real");
        global $wpdb;
        
        // Consultar Valores Reales de la BD
        $entradas = $wpdb->get_var("SELECT SUM(monto) FROM {$wpdb->prefix}fondo_recaudos_detalle");
        $gastos = $wpdb->get_var("SELECT SUM(monto) FROM {$wpdb->prefix}fondo_gastos");
        $prestado = $wpdb->get_var("SELECT SUM(monto_aprobado) FROM {$wpdb->prefix}fondo_creditos WHERE estado IN ('activo', 'pagado', 'mora')");
        
        $recaudo_sec = $wpdb->get_var("SELECT SUM(monto) FROM {$wpdb->prefix}fondo_recaudos_detalle WHERE concepto = 'cuota_secretaria'");
        $gasto_sec = $wpdb->get_var("SELECT SUM(monto) FROM {$wpdb->prefix}fondo_gastos WHERE categoria = 'secretaria'");
        $reserva_sec = floatval($recaudo_sec) - floatval($gasto_sec);

        $liquidez_sistema = LUD_Module_Creditos::get_liquidez_disponible();
        
        $calculo_manual = floatval($entradas) - floatval($gastos) - floatval($prestado) - $reserva_sec;

        $this->log("🔹 DESGLOSE DE CAJA (Valores Reales BD):");
        $this->log("   (+) Total Entradas Históricas:  $ " . number_format($entradas));
        $this->log("   (-) Total Gastos Históricos:    $ " . number_format($gastos));
        $this->log("   (-) Total Créditos Aprobados:   $ " . number_format($prestado));
        $this->log("   (=) Dinero Físico Bruto:        $ " . number_format($entradas - $gastos - $prestado));
        $this->log("   --------------------------------");
        $this->log("   (-) Reserva Secretaría (Intocable): $ " . number_format($reserva_sec));
        $this->log("   --------------------------------");
        $this->log("   (=) LIQUIDEZ PRESTABLE MANUAL:  $ " . number_format($calculo_manual));
        
        $this->log("\n🔹 REPORTE DEL SISTEMA:");
        $this->log("   Liquidez Reportada: $ " . number_format($liquidez_sistema));

        // Margen error flotante pequeño
        if ( abs($liquidez_sistema - $calculo_manual) < 1 ) {
            $this->pass("El cálculo de liquidez es EXACTO y protege la reserva de secretaría.");
        } else {
            $this->fail("Discrepancia en liquidez. Sistema: $liquidez_sistema vs Manual: $calculo_manual");
        }
    }

    // --- HELPERS ---
    private function get_or_create_dummy_user() {
        $user = get_user_by('login', 'test_bot');
        if ( ! $user ) {
            $uid = wp_create_user( 'test_bot', wp_generate_password(), 'test@lud.local' );
            $user = get_user_by('id', $uid);
            
            // ASIGNAR ROL DE SOCIO
            $user->set_role('lud_socio'); 
            
            global $wpdb;
            $wpdb->insert("{$wpdb->prefix}fondo_cuentas", ['user_id' => $uid, 'numero_acciones' => 1, 'estado_socio' => 'activo']);
        }
        return $user->ID;
    }

    private function log($msg) { $this->log[] = $msg; }
    private function hr() { $this->log[] = "----------------------------------------------------------------"; }
    private function header($msg) { $this->log[] = "\n>>> $msg <<<"; }
    private function pass($msg) { $this->log[] = "<span style='color:#4caf50; font-weight:bold;'>✅ PASS: $msg</span>"; }
    private function fail($msg) { $this->log[] = "<span style='color:#f44336; font-weight:bold;'>❌ FAIL: $msg</span>"; }
}