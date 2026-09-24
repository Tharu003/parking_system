<?php 
include('config/db.php');
include('includes/header.php'); 

// Live parked vehicles grouped into six dedicated parking zones.
// Capacities: Bike 20, Car 10, Bus 5, Three-Wheel 20, Van 10, VIP 5.
$parked_query = $conn->query("
    SELECT t.id, t.vehicle_number, t.entry_time, t.is_vip, vt.type_name
    FROM tickets t
    JOIN vehicle_types vt ON t.vehicle_type_id = vt.id
    WHERE t.status = 'PARKED'
    ORDER BY t.entry_time ASC
");

$parked_bikes=[]; $parked_cars=[]; $parked_buses=[];
$parked_threewheel=[]; $parked_vans=[]; $parked_vips=[];

while ($row = $parked_query->fetch_assoc()) {
    $cat = strtolower(trim($row['type_name']));
    if ((int)$row['is_vip'] === 1) {
        $parked_vips[] = $row;
    } elseif (strpos($cat,'three') !== false) {
        $parked_threewheel[] = $row;
    } elseif (strpos($cat,'bike') !== false || strpos($cat,'motor') !== false) {
        $parked_bikes[] = $row;
    } elseif (strpos($cat,'bus') !== false || strpos($cat,'heavy') !== false || strpos($cat,'lorry') !== false) {
        $parked_buses[] = $row;
    } elseif (strpos($cat,'van') !== false) {
        $parked_vans[] = $row;
    } else {
        $parked_cars[] = $row;
    }
}

$CAPACITY=['bike'=>20,'car'=>10,'bus'=>5,'threewheel'=>20,'van'=>10,'vip'=>5];

$new_parked_id = isset($_GET['new_id']) ? intval($_GET['new_id']) : 0;
?>

<style>
    @keyframes fadeSlideUp { from { opacity:0; transform:translateY(10px);} to { opacity:1; transform:translateY(0);} }
    @keyframes pulseDot { 0%,100%{ opacity:1; transform:scale(1);} 50%{ opacity:.55; transform:scale(.8);} }
    @keyframes spin { to { transform: rotate(360deg); } }

    .parking-hero-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 20px;
        margin-bottom: 22px;
        animation: fadeSlideUp .4s ease both;
    }

    .parking-hero-header h2 {
        font-size: 24px;
        font-weight: 800;
        letter-spacing: -.3px;
        color: var(--dark-blue, #0f172a);
        display: flex;
        align-items: center;
        gap: 12px;
        margin: 0;
    }

    .hero-icon-badge {
        width: 44px; height: 44px; border-radius: 13px;
        display: flex; align-items: center; justify-content: center;
        background: linear-gradient(135deg,#fb923c,#f97316);
        color: #fff; font-size: 18px;
        box-shadow: 0 8px 18px rgba(249,115,22,.35);
    }

    .live-badge {
        display: inline-flex; align-items: center; gap: 7px;
        background: rgba(16,185,129,.12); color: #059669;
        padding: 7px 14px; border-radius: 999px; font-size: 12px; font-weight: 700;
        border: 1px solid rgba(16,185,129,.25);
    }
    .live-badge .dot { width: 8px; height: 8px; border-radius: 50%; background: #10b981; animation: pulseDot 1.6s ease-in-out infinite; }

    .stats-container {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        margin-bottom: 25px;
    }

    .stat-card {
        position: relative;
        background: #ffffff;
        border-radius: 16px;
        padding: 18px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
        border: 1px solid rgba(0, 0, 0, 0.08);
        display: flex;
        align-items: center;
        justify-content: space-between;
        overflow: hidden;
        transition: transform .2s ease, box-shadow .2s ease;
        animation: fadeSlideUp .45s ease both;
    }
    .stat-card:hover { transform: translateY(-4px); box-shadow: 0 12px 28px rgba(0,0,0,.09); }
    .stat-card::before {
        content: ""; position: absolute; top: 0; left: 0; right: 0; height: 3px;
        background: var(--accent, #94a3b8);
    }
    .stat-card.stat-bike { --accent: #10b981; }
    .stat-card.stat-car { --accent: #3b82f6; }
    .stat-card.stat-bus { --accent: #ef4444; }
    .stat-card.stat-threewheel { --accent: #a855f7; }
    .stat-card.stat-van { --accent: #f59e0b; }
    .stat-card.stat-vip { --accent: #eab308; }

    .stat-info span { font-size: 11.5px; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; }
    .stat-info h3 { font-size: 23px; font-weight: 800; color: #0f172a; margin-top: 4px; margin-bottom: 0; }
    .stat-info h3 small { font-size: 13px; font-weight: 600; color: #94a3b8; }

    .stat-icon {
        width: 46px; height: 46px; border-radius: 13px;
        display: flex; align-items: center; justify-content: center; font-size: 18px;
        flex-shrink: 0;
    }

    .icon-threewheel { background: rgba(168,85,247,.15); color:#a855f7; }
    .icon-van { background: rgba(245,158,11,.15); color:#f59e0b; }
    .icon-vip { background: rgba(234,179,8,.15); color:#eab308; }
    .icon-bike { background: rgba(16, 185, 129, 0.15); color: #10b981; }
    .icon-car { background: rgba(59, 130, 246, 0.15); color: #3b82f6; }
    .icon-bus { background: rgba(239, 68, 68, 0.15); color: #ef4444; }

    .view-3d-wrapper {
        position: relative;
        background: radial-gradient(ellipse at 50% 0%, #10192c 0%, #090d16 65%);
        border-radius: 20px;
        overflow: hidden;
        box-shadow: 0 20px 45px rgba(0, 0, 0, 0.45);
        height: 620px; width: 100%;
        border: 1px solid #1e293b;
    }

    #canvas-container { width: 100%; height: 100%; cursor: grab; opacity: 0; transition: opacity .6s ease; }
    #canvas-container.ready { opacity: 1; }
    #canvas-container:active { cursor: grabbing; }

    .scene-loading {
        position: absolute; inset: 0; z-index: 20;
        display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 14px;
        background: #090d16; color: #94a3b8; font-size: 13px; font-weight: 600;
        transition: opacity .4s ease, visibility .4s ease;
    }
    .scene-loading.hide { opacity: 0; visibility: hidden; pointer-events: none; }
    .scene-spinner {
        width: 38px; height: 38px; border-radius: 50%;
        border: 3px solid rgba(56,189,248,.2); border-top-color: #38bdf8;
        animation: spin .8s linear infinite;
    }

    .floating-zone-panel {
        position: absolute; top: 20px; left: 20px; z-index: 10;
        background: rgba(15, 23, 42, 0.82); backdrop-filter: blur(14px);
        border: 1px solid rgba(255, 255, 255, 0.12); padding: 18px;
        border-radius: 16px; width: 270px; color: #fff;
        box-shadow: 0 10px 25px rgba(0,0,0,0.5);
    }

    .floating-zone-panel h4 { font-size: 13px; margin-bottom: 14px; color: #38bdf8; display: flex; align-items: center; gap: 8px; font-weight: 700; letter-spacing: .2px; text-transform: uppercase; }
    .zone-indicator { margin-bottom: 11px; }
    .zone-indicator:last-child { margin-bottom: 0; }
    .zone-indicator .zone-row { display: flex; align-items: center; justify-content: space-between; font-size: 12px; margin-bottom: 5px; }
    .zone-indicator .zone-free { font-weight: 700; color: #e2e8f0; }
    .zone-bar-track { height: 5px; border-radius: 999px; background: rgba(255,255,255,.1); overflow: hidden; }
    .zone-bar-fill { height: 100%; border-radius: 999px; transition: width .5s ease; }

    .zone-pill { padding: 3px 8px; border-radius: 6px; font-weight: 700; font-size: 10px; }

    .pill-threewheel { background:#a855f7;color:#fff; }
    .pill-van { background:#f59e0b;color:#fff; }
    .pill-vip { background:#eab308;color:#111827; }
    .pill-bike { background: #10b981; color: #fff; }
    .pill-car { background: #3b82f6; color: #fff; }
    .pill-bus { background: #ef4444; color: #fff; }

    .scene-controls-hint {
        position: absolute; top: 20px; right: 20px; z-index: 10;
        background: rgba(15,23,42,.75); backdrop-filter: blur(10px);
        border: 1px solid rgba(255,255,255,.12); color: #cbd5e1;
        font-size: 11px; font-weight: 600; padding: 9px 14px; border-radius: 12px;
        display: flex; align-items: center; gap: 8px;
    }

    .scene-reset-btn {
        position: absolute; bottom: 18px; right: 20px; z-index: 10;
        background: rgba(15,23,42,.85); border: 1px solid rgba(56,189,248,.4);
        color: #38bdf8; font-size: 12px; font-weight: 700; padding: 9px 16px;
        border-radius: 12px; cursor: pointer; display: flex; align-items: center; gap: 7px;
        transition: background .2s ease, transform .2s ease;
    }
    .scene-reset-btn:hover { background: rgba(56,189,248,.15); transform: translateY(-2px); }

    .alert-parked-success {
        background: linear-gradient(135deg,#10b981,#059669); color: white; padding: 13px 20px; border-radius: 12px;
        margin-bottom: 20px; font-weight: 600; display: flex; align-items: center; gap: 10px;
        box-shadow: 0 8px 20px rgba(16, 185, 129, 0.25);
        animation: fadeSlideUp .35s ease both;
    }

    #vehicle-tooltip {
        position: absolute;
        display: none;
        background: rgba(15, 23, 42, 0.97);
        color: #fff;
        padding: 11px 15px;
        border-radius: 10px;
        font-size: 12px;
        line-height: 1.6;
        border: 1px solid #38bdf8;
        pointer-events: none;
        z-index: 100;
        box-shadow: 0 8px 22px rgba(0,0,0,.55);
    }
</style>

<?php if ($new_parked_id > 0): ?>
    <div class="alert-parked-success">
        <i class="fa-solid fa-circle-check" style="font-size: 20px;"></i> 
        වාහනය සාර්ථකව System එකට Park කරන ලදී! 3D Space එකේ Render කර ඇත.
    </div>
<?php endif; ?>

<div class="parking-hero-header">
    <div>
        <h2><span class="hero-icon-badge"><i class="fa-solid fa-cubes"></i></span> Interactive Real 3D Vehicle Parking Slot Visualizer</h2>
        <p style="color: #64748b; font-size: 13px; margin: 6px 0 0 56px;">Live visualization of parked 3D vehicles synced with Database.</p>
    </div>
    <span class="live-badge"><span class="dot"></span> LIVE</span>
</div>

<!-- Dynamic Capacity Cards -->
<div class="stats-container">
<?php
$cards=[
 ['Bikes Parked','bike',$parked_bikes,'fa-motorcycle','icon-bike'],
 ['Cars Parked','car',$parked_cars,'fa-car','icon-car'],
 ['Buses Parked','bus',$parked_buses,'fa-bus','icon-bus'],
 ['Three-Wheel Parked','threewheel',$parked_threewheel,'fa-car-side','icon-threewheel'],
 ['Vans Parked','van',$parked_vans,'fa-van-shuttle','icon-van'],
 ['VIP Parked','vip',$parked_vips,'fa-crown','icon-vip']
];
foreach($cards as $i=>$c):
?>
<div class="stat-card stat-<?php echo $c[1]; ?>" style="animation-delay:<?php echo $i*0.05; ?>s;">
 <div class="stat-info"><span><?php echo $c[0]; ?></span><h3><?php echo count($c[2]); ?> <small>/ <?php echo $CAPACITY[$c[1]]; ?></small></h3></div>
 <div class="stat-icon <?php echo $c[4]; ?>"><i class="fa-solid <?php echo $c[3]; ?>"></i></div>
</div>
<?php endforeach; ?>
</div>

<!-- 3D Canvas Box -->
<div class="view-3d-wrapper">
    <div class="floating-zone-panel">
        <h4><i class="fa-solid fa-layer-group"></i> Zone Live Availability</h4>
        <?php
        $zones = [
            ['pill-bike','A','Bike','bike',$parked_bikes,'#10b981'],
            ['pill-car','B','Car','car',$parked_cars,'#3b82f6'],
            ['pill-bus','C','Bus','bus',$parked_buses,'#ef4444'],
            ['pill-threewheel','D','Three-Wheel','threewheel',$parked_threewheel,'#a855f7'],
            ['pill-van','E','Van','van',$parked_vans,'#f59e0b'],
            ['pill-vip','VIP','VIP Reserved','vip',$parked_vips,'#eab308'],
        ];
        foreach ($zones as $z):
            [$pillClass,$label,$name,$key,$list,$color] = $z;
            $cap = $CAPACITY[$key];
            $used = count($list);
            $pct = $cap > 0 ? min(100, round($used / $cap * 100)) : 0;
        ?>
        <div class="zone-indicator">
            <div class="zone-row">
                <span><span class="zone-pill <?php echo $pillClass; ?>"><?php echo $label; ?></span> <?php echo $name; ?></span>
                <span class="zone-free"><?php echo $cap-$used; ?> Free</span>
            </div>
            <div class="zone-bar-track"><div class="zone-bar-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $color; ?>;"></div></div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="scene-controls-hint"><i class="fa-solid fa-arrows-rotate"></i> Drag to rotate &nbsp;•&nbsp; Scroll to zoom</div>

    <div style="position:absolute;bottom:18px;left:20px;z-index:10;background:rgba(15,23,42,.9);border:1px solid rgba(234,179,8,.55);padding:10px 14px;border-radius:12px;color:#fff;font-size:11px;">
        <strong style="color:#eab308;">👑 VIP RESERVED ZONE</strong> &nbsp; 5 Dedicated Slots &nbsp; • &nbsp; Parking Fee: LKR 0.00
    </div>

    <button type="button" class="scene-reset-btn" id="reset-view-btn"><i class="fa-solid fa-camera-rotate"></i> Reset View</button>

    <div class="scene-loading" id="scene-loading">
        <div class="scene-spinner"></div>
        <span>Rendering 3D parking yard…</span>
    </div>

    <div id="vehicle-tooltip"></div>
    <div id="canvas-container"></div>
</div>

<!-- Three.js Libraries -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/OrbitControls.js"></script>

<script>
    const dbBikes = <?php echo json_encode($parked_bikes); ?>;
    const dbCars  = <?php echo json_encode($parked_cars); ?>;
    const dbBuses = <?php echo json_encode($parked_buses); ?>;
    const dbThreeWheel = <?php echo json_encode($parked_threewheel); ?>;
    const dbVans = <?php echo json_encode($parked_vans); ?>;
    const dbVIP = <?php echo json_encode($parked_vips); ?>;

    const container = document.getElementById('canvas-container');
    const tooltip = document.getElementById('vehicle-tooltip');

    // --- Three.js Scene Setup ---
    const scene = new THREE.Scene();
    scene.background = new THREE.Color(0x0a0e17);
    scene.fog = new THREE.FogExp2(0x0a0e17, 0.007);

    const camera = new THREE.PerspectiveCamera(45, container.clientWidth / container.clientHeight, 0.1, 1000);
    camera.position.set(45, 45, 60);

    const renderer = new THREE.WebGLRenderer({ antialias: true });
    renderer.setSize(container.clientWidth, container.clientHeight);
    renderer.setPixelRatio(window.devicePixelRatio);
    renderer.shadowMap.enabled = true;
    renderer.shadowMap.type = THREE.PCFSoftShadowMap;
    container.appendChild(renderer.domElement);

    const controls = new THREE.OrbitControls(camera, renderer.domElement);
    controls.enableDamping = true;
    controls.dampingFactor = 0.05;
    controls.maxPolarAngle = Math.PI / 2.05;

    const DEFAULT_CAM_POS = { x: 45, y: 45, z: 60 };
    const resetBtn = document.getElementById('reset-view-btn');
    if (resetBtn) {
        resetBtn.addEventListener('click', () => {
            camera.position.set(DEFAULT_CAM_POS.x, DEFAULT_CAM_POS.y, DEFAULT_CAM_POS.z);
            controls.target.set(0, 0, 0);
            controls.update();
        });
    }

    // --- Studio Lighting Setup for Clear Pearl White View ---
    const ambientLight = new THREE.AmbientLight(0xffffff, 0.8);
    scene.add(ambientLight);

    const mainLight = new THREE.DirectionalLight(0xffffff, 1.2);
    mainLight.position.set(50, 70, 40);
    mainLight.castShadow = true;
    mainLight.shadow.mapSize.width = 2048;
    mainLight.shadow.mapSize.height = 2048;
    scene.add(mainLight);

    const blueRimLight = new THREE.DirectionalLight(0x38bdf8, 0.5);
    blueRimLight.position.set(-40, 30, -40);
    scene.add(blueRimLight);

    // Ground Asphalt Flooring
    const groundGeo = new THREE.PlaneGeometry(130, 90);
    const groundMat = new THREE.MeshStandardMaterial({ color: 0x111827, roughness: 0.9, metalness: 0.1 });
    const ground = new THREE.Mesh(groundGeo, groundMat);
    ground.rotation.x = -Math.PI / 2;
    ground.receiveShadow = true;
    scene.add(ground);

    // Subtle reference grid for depth and polish
    const gridHelper = new THREE.GridHelper(130, 26, 0x1e293b, 0x151f30);
    gridHelper.position.y = 0.005;
    scene.add(gridHelper);

    // Slots Coordinate Management
    const slots = { bike: [], car: [], bus: [], threewheel: [], van: [], vip: [] };
    const interactiveObjects = [];

    // Helper for Parking Slot Lines
    function createSlot(x, z, w, l, colorHex, type) {
        const group = new THREE.Group();

        const slotMat = new THREE.MeshStandardMaterial({ color: 0x1e293b, roughness: 0.8 });
        const slotMesh = new THREE.Mesh(new THREE.PlaneGeometry(w, l), slotMat);
        slotMesh.rotation.x = -Math.PI / 2;
        slotMesh.receiveShadow = true;
        group.add(slotMesh);

        const edges = new THREE.EdgesGeometry(new THREE.PlaneGeometry(w, l));
        const lineMat = new THREE.LineBasicMaterial({ color: colorHex, linewidth: 2 });
        const border = new THREE.LineSegments(edges, lineMat);
        border.rotation.x = -Math.PI / 2;
        border.position.y = 0.02;
        group.add(border);

        group.position.set(x, 0.01, z);
        scene.add(group);

        slots[type].push({ x: x, z: z });
    }

    // --- Layout Generation ---
    // A - Bike: 20 dedicated slots
    for (let r=0;r<2;r++) for(let i=0;i<10;i++)
        createSlot(-46+(i*4),-30+(r*7),3,5.2,0x10b981,'bike');

    // B - Car: 10 dedicated slots
    for(let i=0;i<10;i++)
        createSlot(-46+(i*9),-10,6.5,8.5,0x3b82f6,'car');

    // C - Bus: 5 dedicated slots
    for(let i=0;i<5;i++)
        createSlot(-36+(i*18),15,10.5,15,0xef4444,'bus');

    // D - Three-Wheel: 20 dedicated slots
    for(let r=0;r<2;r++) for(let i=0;i<10;i++)
        createSlot(-46+(i*4),29+(r*6),3,4.5,0xa855f7,'threewheel');

    // E - Van: 10 dedicated slots
    for(let i=0;i<10;i++)
        createSlot(-46+(i*9),43,6.5,8.5,0xf59e0b,'van');

    // VIP - 5 completely separate reserved slots
    for(let i=0;i<5;i++)
        createSlot(-36+(i*18),58,11,15,0xeab308,'vip');

    // --- SHARED HIGH-QUALITY MATERIALS ---
    const whitePaintMat = new THREE.MeshStandardMaterial({
        color: 0xf8fafc,
        metalness: 0.2,
        roughness: 0.1,
    });

    const darkGlassMat = new THREE.MeshStandardMaterial({
        color: 0x0f172a,
        metalness: 0.9,
        roughness: 0.05,
    });

    const rubberTireMat = new THREE.MeshStandardMaterial({
        color: 0x18181b,
        roughness: 0.9
    });

    const chromeRimMat = new THREE.MeshStandardMaterial({
        color: 0xe2e8f0,
        metalness: 0.95,
        roughness: 0.1
    });

    // --- HIGH-DETAILED 3D VEHICLE GENERATORS ---

    // 1. REALISTIC WHITE CAR
    function buildWhiteCar(x, z, info) {
        const car = new THREE.Group();

        // Main Body Shell
        const bodyGeo = new THREE.BoxGeometry(2.6, 0.85, 5.2);
        const body = new THREE.Mesh(bodyGeo, whitePaintMat);
        body.position.y = 0.7;
        body.castShadow = true;
        car.add(body);

        // Cabin Top (Windshield & Roof)
        const cabinGeo = new THREE.BoxGeometry(2.35, 0.75, 2.8);
        const cabin = new THREE.Mesh(cabinGeo, whitePaintMat);
        cabin.position.set(0, 1.4, -0.2);
        cabin.castShadow = true;
        car.add(cabin);

        // Glass Front & Back Windshield Overlay
        const glassGeo = new THREE.BoxGeometry(2.2, 0.65, 2.6);
        const glass = new THREE.Mesh(glassGeo, darkGlassMat);
        glass.position.set(0, 1.42, -0.2);
        car.add(glass);

        // Headlights
        const headLight = new THREE.Mesh(
            new THREE.BoxGeometry(0.5, 0.2, 0.1),
            new THREE.MeshBasicMaterial({ color: 0xffffff })
        );
        headLight.position.set(-0.9, 0.75, -2.61);
        const headLightR = headLight.clone();
        headLightR.position.x = 0.9;
        car.add(headLight);
        car.add(headLightR);

        // Rear Brake Lights
        const tailLight = new THREE.Mesh(
            new THREE.BoxGeometry(0.5, 0.2, 0.1),
            new THREE.MeshBasicMaterial({ color: 0xef4444 })
        );
        tailLight.position.set(-0.9, 0.75, 2.61);
        const tailLightR = tailLight.clone();
        tailLightR.position.x = 0.9;
        car.add(tailLight);
        car.add(tailLightR);

        // 4 Realistic Wheels with Chrome Rims
        const wheelGroupGeo = new THREE.CylinderGeometry(0.42, 0.42, 0.35, 24);
        wheelGroupGeo.rotateZ(Math.PI / 2);

        const wheelPositions = [
            [-1.35, 0.42, -1.6], [1.35, 0.42, -1.6],
            [-1.35, 0.42, 1.6],  [1.35, 0.42, 1.6]
        ];

        wheelPositions.forEach(pos => {
            const wGroup = new THREE.Group();
            const tire = new THREE.Mesh(wheelGroupGeo, rubberTireMat);
            tire.castShadow = true;

            const rimGeo = new THREE.CylinderGeometry(0.25, 0.25, 0.36, 12);
            rimGeo.rotateZ(Math.PI / 2);
            const rim = new THREE.Mesh(rimGeo, chromeRimMat);

            wGroup.add(tire);
            wGroup.add(rim);
            wGroup.position.set(...pos);
            car.add(wGroup);
        });

        car.position.set(x, 0, z);
        scene.add(car);

        body.userData = info;
        interactiveObjects.push(body);
    }

    // 2. REALISTIC WHITE MOTORBIKE
    function buildWhiteBike(x, z, info) {
        const bike = new THREE.Group();

        // Main Tank Body
        const tank = new THREE.Mesh(new THREE.BoxGeometry(0.65, 0.55, 1.2), whitePaintMat);
        tank.position.set(0, 0.85, -0.1);
        tank.castShadow = true;
        bike.add(tank);

        // Black Engine Chassis Block
        const engine = new THREE.Mesh(
            new THREE.BoxGeometry(0.5, 0.45, 0.8),
            new THREE.MeshStandardMaterial({ color: 0x334155, metalness: 0.8 })
        );
        engine.position.set(0, 0.5, -0.1);
        bike.add(engine);

        // Leather Seat
        const seat = new THREE.Mesh(new THREE.BoxGeometry(0.5, 0.15, 0.7), darkGlassMat);
        seat.position.set(0, 0.95, 0.35);
        bike.add(seat);

        // Handlebars
        const handle = new THREE.Mesh(new THREE.CylinderGeometry(0.04, 0.04, 1.3), chromeRimMat);
        handle.rotation.z = Math.PI / 2;
        handle.position.set(0, 1.15, -0.65);
        bike.add(handle);

        // Front Headlight
        const headLamp = new THREE.Mesh(
            new THREE.SphereGeometry(0.2, 16, 16),
            new THREE.MeshBasicMaterial({ color: 0xffffff })
        );
        headLamp.position.set(0, 1.05, -0.8);
        bike.add(headLamp);

        // Wheels
        const wheelGeo = new THREE.CylinderGeometry(0.38, 0.38, 0.15, 20);
        wheelGeo.rotateZ(Math.PI / 2);

        const frontWheel = new THREE.Mesh(wheelGeo, rubberTireMat);
        frontWheel.position.set(0, 0.38, -0.85);
        frontWheel.castShadow = true;

        const rearWheel = frontWheel.clone();
        rearWheel.position.z = 0.75;

        bike.add(frontWheel);
        bike.add(rearWheel);

        bike.position.set(x, 0, z);
        scene.add(bike);

        tank.userData = info;
        interactiveObjects.push(tank);
    }

    // 3. REALISTIC WHITE BUS / HEAVY VEHICLE
    function buildWhiteBus(x, z, info) {
        const bus = new THREE.Group();

        // Main Bus Body
        const bodyGeo = new THREE.BoxGeometry(4.2, 2.7, 12.0);
        const body = new THREE.Mesh(bodyGeo, whitePaintMat);
        body.position.y = 1.75;
        body.castShadow = true;
        bus.add(body);

        // Passenger Side Windows Strips
        const glassGeo = new THREE.BoxGeometry(4.3, 0.95, 10.5);
        const glass = new THREE.Mesh(glassGeo, darkGlassMat);
        glass.position.set(0, 2.1, -0.2);
        bus.add(glass);

        // Front Windshield
        const frontWind = new THREE.Mesh(
            new THREE.BoxGeometry(4.1, 1.1, 0.1),
            darkGlassMat
        );
        frontWind.position.set(0, 2.0, -6.01);
        bus.add(frontWind);

        // 6 Large Bus Wheels
        const wheelGeo = new THREE.CylinderGeometry(0.65, 0.65, 0.4, 24);
        wheelGeo.rotateZ(Math.PI / 2);

        const wheelPositions = [
            [-2.2, 0.65, -4.0], [2.2, 0.65, -4.0],
            [-2.2, 0.65, 2.8],  [2.2, 0.65, 2.8],
            [-2.2, 0.65, 4.6],  [2.2, 0.65, 4.6]
        ];

        wheelPositions.forEach(pos => {
            const tire = new THREE.Mesh(wheelGeo, rubberTireMat);
            tire.position.set(...pos);
            tire.castShadow = true;
            bus.add(tire);
        });

        bus.position.set(x, 0, z);
        scene.add(bus);

        body.userData = info;
        interactiveObjects.push(body);
    }


    function buildWhiteVan(x,z,info){
        const van=new THREE.Group();
        const body=new THREE.Mesh(new THREE.BoxGeometry(3.5,1.8,6.5),whitePaintMat);
        body.position.y=1.25; body.castShadow=true; van.add(body);
        const cabin=new THREE.Mesh(new THREE.BoxGeometry(3.25,1.05,3.2),whitePaintMat);
        cabin.position.set(0,2.25,-0.5); cabin.castShadow=true; van.add(cabin);
        const glass=new THREE.Mesh(new THREE.BoxGeometry(3.05,.78,2.7),darkGlassMat);
        glass.position.set(0,2.28,-.5); van.add(glass);
        const wg=new THREE.CylinderGeometry(.48,.48,.38,24); wg.rotateZ(Math.PI/2);
        [[-1.8,.5,-2],[1.8,.5,-2],[-1.8,.5,2],[1.8,.5,2]].forEach(pos=>{
            const w=new THREE.Mesh(wg,rubberTireMat); w.position.set(...pos); w.castShadow=true; van.add(w);
        });
        van.position.set(x,0,z); scene.add(van); body.userData=info; interactiveObjects.push(body);
    }

    function buildThreeWheel(x,z,info){
        const tuk=new THREE.Group();
        const body=new THREE.Mesh(new THREE.BoxGeometry(1.7,1.35,2.8),whitePaintMat);
        body.position.y=1.05; body.castShadow=true; tuk.add(body);
        const roof=new THREE.Mesh(new THREE.BoxGeometry(1.9,.15,2.4),darkGlassMat);
        roof.position.set(0,2,.1); tuk.add(roof);
        const wg=new THREE.CylinderGeometry(.42,.42,.18,20); wg.rotateZ(Math.PI/2);
        [[0,.42,-1.05],[-.9,.42,.9],[.9,.42,.9]].forEach(pos=>{
            const w=new THREE.Mesh(wg,rubberTireMat); w.position.set(...pos); w.castShadow=true; tuk.add(w);
        });
        tuk.position.set(x,0,z); scene.add(tuk); body.userData=info; interactiveObjects.push(body);
    }

    function buildVIPVehicle(x,z,info){
        const vip=new THREE.Group();
        const vipMat=new THREE.MeshStandardMaterial({color:0x111827,metalness:.7,roughness:.16});
        const goldMat=new THREE.MeshStandardMaterial({color:0xeab308,metalness:.9,roughness:.16});
        const body=new THREE.Mesh(new THREE.BoxGeometry(5,1.15,8),vipMat);
        body.position.y=.95; body.castShadow=true; vip.add(body);
        const cabin=new THREE.Mesh(new THREE.BoxGeometry(4.5,1,4),darkGlassMat);
        cabin.position.set(0,1.85,-.25); vip.add(cabin);
        const trim=new THREE.Mesh(new THREE.BoxGeometry(5.05,.12,7.7),goldMat);
        trim.position.y=1.52; vip.add(trim);
        const wg=new THREE.CylinderGeometry(.55,.55,.42,28); wg.rotateZ(Math.PI/2);
        [[-2.5,.55,-2.5],[2.5,.55,-2.5],[-2.5,.55,2.5],[2.5,.55,2.5]].forEach(pos=>{
            const w=new THREE.Mesh(wg,rubberTireMat); w.position.set(...pos); w.castShadow=true; vip.add(w);
        });
        // Gold VIP marker above the vehicle
        const marker=new THREE.Mesh(new THREE.CylinderGeometry(.8,.8,.12,32),goldMat);
        marker.position.y=3.1; vip.add(marker);
        vip.position.set(x,0,z); scene.add(vip);
        body.userData=Object.assign({},info,{is_vip:1}); interactiveObjects.push(body);
    }

    // --- MAP DATABASE VEHICLES TO 3D MODELS ---
    dbBikes.forEach((item,i)=>{if(slots.bike[i])buildWhiteBike(slots.bike[i].x,slots.bike[i].z,item);});
    dbCars.forEach((item,i)=>{if(slots.car[i])buildWhiteCar(slots.car[i].x,slots.car[i].z,item);});
    dbBuses.forEach((item,i)=>{if(slots.bus[i])buildWhiteBus(slots.bus[i].x,slots.bus[i].z,item);});
    dbThreeWheel.forEach((item,i)=>{if(slots.threewheel[i])buildThreeWheel(slots.threewheel[i].x,slots.threewheel[i].z,item);});
    dbVans.forEach((item,i)=>{if(slots.van[i])buildWhiteVan(slots.van[i].x,slots.van[i].z,item);});
    dbVIP.forEach((item,i)=>{if(slots.vip[i])buildVIPVehicle(slots.vip[i].x,slots.vip[i].z,item);});

    // --- INTERACTIVE MOUSE HOVER DETECTION ---
    const raycaster = new THREE.Raycaster();
    const mouse = new THREE.Vector2();

    window.addEventListener('mousemove', (e) => {
        const rect = renderer.domElement.getBoundingClientRect();
        mouse.x = ((e.clientX - rect.left) / container.clientWidth) * 2 - 1;
        mouse.y = -((e.clientY - rect.top) / container.clientHeight) * 2 + 1;

        raycaster.setFromCamera(mouse, camera);
        const intersects = raycaster.intersectObjects(interactiveObjects);

        if (intersects.length > 0) {
            const data = intersects[0].object.userData;
            tooltip.style.display = 'block';
            tooltip.style.left = (e.clientX - rect.left + 15) + 'px';
            tooltip.style.top = (e.clientY - rect.top + 15) + 'px';
            tooltip.innerHTML = `
                <strong style="color:#38bdf8; font-size:13px;">${data.vehicle_number}</strong><br>
                <span>Category: ${data.type_name}</span><br>
                <span>Time: ${data.entry_time}</span>
            `;
            document.body.style.cursor = 'pointer';
        } else {
            tooltip.style.display = 'none';
            document.body.style.cursor = 'default';
        }
    });

    // Responsive Canvas Resize
    window.addEventListener('resize', () => {
        camera.aspect = container.clientWidth / container.clientHeight;
        camera.updateProjectionMatrix();
        renderer.setSize(container.clientWidth, container.clientHeight);
    });

    // Main Render Loop
    let firstFrame = true;
    function animate() {
        requestAnimationFrame(animate);
        controls.update();
        renderer.render(scene, camera);
        if (firstFrame) {
            firstFrame = false;
            const loadingEl = document.getElementById('scene-loading');
            if (loadingEl) loadingEl.classList.add('hide');
            container.classList.add('ready');
        }
    }
    animate();
</script>

<?php include('includes/footer.php'); ?>