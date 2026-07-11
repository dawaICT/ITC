<?php
$page_title = "Room Management";
require_once "includes/admin.php";
require_once "includes/header.php";
error_reporting(0);

// Get room statistics
$total_rooms = 0;
$occupied_rooms = 0;
$available_rooms = 0;
$maintenance_rooms = 0;

try {
    $stats_query = "SELECT 
        COUNT(*) as total_rooms,
        SUM(CASE WHEN status = 'occupied' THEN 1 ELSE 0 END) as occupied_rooms,
        SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available_rooms,
        SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) as maintenance_rooms
    FROM rooms";

    if($result = $db->query($stats_query)) {
        $stats = $result->fetch_object();
        $total_rooms = $stats->total_rooms;
        $occupied_rooms = $stats->occupied_rooms;
        $available_rooms = $stats->available_rooms;
        $maintenance_rooms = $stats->maintenance_rooms;
        $result->free();
    }
} catch (Exception $e) {
    error_log("Error fetching room statistics: " . $e->getMessage());
}
?>

<div class="container-fluid px-4">
    <!-- Dashboard Header -->
    <div class="dashboard-header mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title">Room Management</h1>
                <p class="text-muted">Manage hostel rooms, allocations, and maintenance</p>
            </div>
            <div class="col-auto">
                <button class="btn btn-primary d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#addRoomModal">
                    <i class="fas fa-plus"></i> Add New Room
                </button>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row g-4 mb-4">
        <!-- Total Rooms Card -->
        <div class="col-xl-3 col-md-6">
            <div class="stat-card h-100 rounded-3 bg-white p-4">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-primary rounded-circle p-3 me-3">
                        <i class="fas fa-door-open fa-2x text-white"></i>
                    </div>
                    <div>
                        <h3 class="mb-1"><?php echo number_format($total_rooms); ?></h3>
                        <p class="text-muted mb-0">Total Rooms</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Occupied Rooms Card -->
        <div class="col-xl-3 col-md-6">
            <div class="stat-card h-100 rounded-3 bg-white p-4">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-success rounded-circle p-3 me-3">
                        <i class="fas fa-users fa-2x text-white"></i>
                    </div>
                    <div>
                        <h3 class="mb-1"><?php echo number_format($occupied_rooms); ?></h3>
                        <p class="text-muted mb-0">Occupied Rooms</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Available Rooms Card -->
        <div class="col-xl-3 col-md-6">
            <div class="stat-card h-100 rounded-3 bg-white p-4">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-info rounded-circle p-3 me-3">
                        <i class="fas fa-check-circle fa-2x text-white"></i>
                    </div>
                    <div>
                        <h3 class="mb-1"><?php echo number_format($available_rooms); ?></h3>
                        <p class="text-muted mb-0">Available Rooms</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Maintenance Rooms Card -->
        <div class="col-xl-3 col-md-6">
            <div class="stat-card h-100 rounded-3 bg-white p-4">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-warning rounded-circle p-3 me-3">
                        <i class="fas fa-tools fa-2x text-white"></i>
                    </div>
                    <div>
                        <h3 class="mb-1"><?php echo number_format($maintenance_rooms); ?></h3>
                        <p class="text-muted mb-0">Under Maintenance</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Room Management Tools -->
    <div class="row g-4 mb-4">
        <div class="col-12">
            <div class="data-table-card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-tools me-2"></i>Room Management Tools
                        </h5>
                        <div class="header-actions">
                            <button class="btn btn-sm btn-primary" onclick="exportToExcel()">
                                <i class="fas fa-file-excel me-2"></i>Export
                            </button>
                            <button class="btn btn-sm btn-outline-secondary" onclick="window.print()">
                                <i class="fas fa-print me-2"></i>Print
                            </button>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label class="form-label">Filter by Status</label>
                                <select class="form-select" id="statusFilter">
                                    <option value="">All Status</option>
                                    <option value="available">Available</option>
                                    <option value="occupied">Occupied</option>
                                    <option value="maintenance">Maintenance</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label class="form-label">Filter by Block</label>
                                <select class="form-select" id="blockFilter">
                                    <option value="">All Blocks</option>
                                    <option value="A">Block A</option>
                                    <option value="B">Block B</option>
                                    <option value="C">Block C</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label class="form-label">Filter by Floor</label>
                                <select class="form-select" id="floorFilter">
                                    <option value="">All Floors</option>
                                    <option value="1">1st Floor</option>
                                    <option value="2">2nd Floor</option>
                                    <option value="3">3rd Floor</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label class="form-label">Search</label>
                                <input type="text" class="form-control" id="searchInput" placeholder="Search rooms...">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Rooms Table -->
    <div class="row g-4">
        <div class="col-12">
            <div class="data-table-card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-door-open me-2"></i>Rooms List</h5>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="roomsTable" class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Room No.</th>
                                    <th>Block</th>
                                    <th>Floor</th>
                                    <th>Capacity</th>
                                    <th>Occupants</th>
                                    <th>Status</th>
                                    <th>Last Maintenance</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                try {
                                    $rooms_query = "SELECT * FROM rooms ORDER BY block, room_number";
                                    if($result = $db->query($rooms_query)) {
                                        while($room = $result->fetch_object()) {
                                            $status_class = [
                                                'available' => 'success',
                                                'occupied' => 'primary',
                                                'maintenance' => 'warning'
                                            ][$room->status] ?? 'secondary';
                                ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($room->room_number); ?></td>
                                        <td><?php echo htmlspecialchars($room->block); ?></td>
                                        <td><?php echo htmlspecialchars($room->floor); ?></td>
                                        <td><?php echo htmlspecialchars($room->capacity); ?></td>
                                        <td><?php echo htmlspecialchars($room->current_occupants); ?>/<?php echo htmlspecialchars($room->capacity); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $status_class; ?>">
                                                <?php echo ucfirst(htmlspecialchars($room->status)); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($room->last_maintenance); ?></td>
                                        <td>
                                            <div class="d-flex justify-content-center gap-2">
                                                <button class="btn btn-sm btn-info" onclick="viewRoom(<?php echo $room->room_id; ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button class="btn btn-sm btn-warning" onclick="editRoom(<?php echo $room->room_id; ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-sm btn-danger" onclick="deleteRoom(<?php echo $room->room_id; ?>)">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php
                                        }
                                        $result->free();
                                    }
                                } catch (Exception $e) {
                                    error_log("Error fetching rooms: " . $e->getMessage());
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Room Modal -->
<div class="modal fade" id="addRoomModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Room</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="addRoomForm">
                    <div class="mb-3">
                        <label class="form-label">Room Number</label>
                        <input type="text" class="form-control" name="room_number" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Block</label>
                        <select class="form-select" name="block" required>
                            <option value="A">Block A</option>
                            <option value="B">Block B</option>
                            <option value="C">Block C</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Floor</label>
                        <input type="number" class="form-control" name="floor" min="1" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Capacity</label>
                        <input type="number" class="form-control" name="capacity" min="1" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status" required>
                            <option value="available">Available</option>
                            <option value="maintenance">Under Maintenance</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveRoom()">Save Room</button>
            </div>
        </div>
    </div>
</div>

<style>
/* stat-card, stat-icon → assets/css/dashboard.css */
.btn-action { width: 32px; height: 32px; padding: 0; display: inline-flex; align-items: center; justify-content: center; }
@media (max-width: 768px) { .btn-action { width: 28px; height: 28px; } }
</style>

<script>
$(document).ready(function() {
    // Initialize DataTable
    $('#roomsTable').DataTable({
        pageLength: 10,
        responsive: true,
        dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>rtip',
        language: {
            search: "",
            searchPlaceholder: "Search rooms...",
            lengthMenu: "Show _MENU_ rooms",
            info: "Showing _START_ to _END_ of _TOTAL_ rooms"
        }
    });

    // Initialize filters
    $('#statusFilter, #blockFilter, #floorFilter').change(function() {
        $('#roomsTable').DataTable().draw();
    });

    $('#searchInput').on('keyup', function() {
        $('#roomsTable').DataTable().search(this.value).draw();
    });
});

function viewRoom(roomId) {
    // Implement room view functionality
    window.location.href = `view_room.php?id=${roomId}`;
}

function editRoom(roomId) {
    // Implement room edit functionality
    window.location.href = `edit_room.php?id=${roomId}`;
}

function deleteRoom(roomId) {
    if(confirm('Are you sure you want to delete this room?')) {
        // Implement room delete functionality
        window.location.href = `delete_room.php?id=${roomId}`;
    }
}

function saveRoom() {
    // Implement room save functionality
    const form = document.getElementById('addRoomForm');
    const formData = new FormData(form);
    
    fetch('save_room.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if(data.success) {
            location.reload();
        } else {
            alert('Error saving room: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while saving the room');
    });
}

function exportToExcel() {
    let table = document.querySelector('#roomsTable');
    let html = table.outerHTML;
    let url = 'data:application/vnd.ms-excel,' + encodeURIComponent(html);
    let downloadLink = document.createElement("a");
    document.body.appendChild(downloadLink);
    downloadLink.href = url;
    downloadLink.download = 'hostel_rooms.xls';
    downloadLink.click();
    document.body.removeChild(downloadLink);
}
</script>

<?php require_once "includes/footer.php"; ?> 