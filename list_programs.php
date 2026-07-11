<?php
// AJAX branch must run BEFORE the chrome include: includes/admin.php emits
// HTML immediately, which made the JSON headers below fatal. programs has no
// `id` column — its key is program_code.
if (isset($_GET['action']) && $_GET['action'] === 'get_programs') {
    require_once __DIR__ . '/db/connect.php';
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    header('Content-Type: application/json');
    if (empty($_SESSION['user_id']) && empty($_SESSION['staff_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 10;
    $offset = ($page - 1) * $limit;

    // Get total count
    $count_result = $db->query("SELECT COUNT(*) as total FROM programs");
    $total_records = $count_result->fetch_assoc()['total'];

    // Get paginated data
    $stmt = $db->prepare("SELECT program_code AS id, program_code, program_name FROM programs ORDER BY program_name LIMIT ? OFFSET ?");
    $stmt->bind_param('ii', $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();

    $programs = array();
    while ($row = $result->fetch_assoc()) {
        $programs[] = $row;
    }

    echo json_encode([
        'programs' => $programs,
        'total' => $total_records,
        'page' => $page,
        'limit' => $limit
    ]);
    exit;
}
include "includes/admin.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Programs Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .program-card {
            transition: transform 0.2s;
        }
        .program-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .pagination {
            margin-top: 20px;
        }
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255,255,255,0.8);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }
        .fade-enter-active, .fade-leave-active {
            transition: opacity 0.3s;
        }
        .fade-enter-from, .fade-enter, .fade-leave-to {
            opacity: 0;
        }
    </style>
</head>
<body>
    <div id="app" class="container py-5">
        <div class="row mb-4">
            <div class="col">
                <h2 class="mb-3">Programs Management</h2>
                <div class="input-group">
                    <input type="text" class="form-control" v-model="searchQuery" placeholder="Search programs...">
                    <button class="btn btn-primary" @click="searchPrograms">
                        <i class="fas fa-search"></i> Search
                    </button>
                </div>
            </div>
        </div>

        <transition name="fade">
            <div class="loading-overlay" v-if="loading">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
            </div>
        </transition>

        <div class="row g-4">
            <div v-for="program in programs" :key="program.id" class="col-md-6 col-lg-4">
                <div class="card program-card h-100">
                    <div class="card-body">
                        <h5 class="card-title">{{ program.program_name }}</h5>
                        <p class="card-text">
                            <small class="text-muted">Code: {{ program.program_code }}</small>
                        </p>
                        <div class="mt-3">
                            <a :href="'admin/programs.php?id=' + program.id" class="btn btn-primary btn-sm">
                                <i class="fas fa-eye"></i> View Details
                            </a>
                            <button class="btn btn-outline-secondary btn-sm ms-2" @click="editProgram(program)">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pagination -->
        <nav v-if="totalPages > 1" class="d-flex justify-content-center">
            <ul class="pagination">
                <li class="page-item" :class="{ disabled: currentPage === 1 }">
                    <a class="page-link" href="#" @click.prevent="changePage(currentPage - 1)">Previous</a>
                </li>
                <li v-for="page in displayedPages" 
                    :key="page" 
                    class="page-item"
                    :class="{ active: currentPage === page }">
                    <a class="page-link" href="#" @click.prevent="changePage(page)">{{ page }}</a>
                </li>
                <li class="page-item" :class="{ disabled: currentPage === totalPages }">
                    <a class="page-link" href="#" @click.prevent="changePage(currentPage + 1)">Next</a>
                </li>
            </ul>
        </nav>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/vue@3/dist/vue.global.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
    <script>
        const { createApp, ref, computed, onMounted } = Vue;

        createApp({
            setup() {
                const programs = ref([]);
                const currentPage = ref(1);
                const totalRecords = ref(0);
                const itemsPerPage = ref(9);
                const loading = ref(false);
                const searchQuery = ref('');
                let searchTimeout = null;

                const totalPages = computed(() => {
                    return Math.ceil(totalRecords.value / itemsPerPage.value);
                });

                const displayedPages = computed(() => {
                    const pages = [];
                    let start = Math.max(1, currentPage.value - 2);
                    let end = Math.min(totalPages.value, start + 4);

                    if (end - start < 4) {
                        start = Math.max(1, end - 4);
                    }

                    for (let i = start; i <= end; i++) {
                        pages.push(i);
                    }
                    return pages;
                });

                const fetchPrograms = async () => {
                    loading.value = true;
                    try {
                        const response = await axios.get('list_programs.php', {
                            params: {
                                action: 'get_programs',
                                page: currentPage.value,
                                limit: itemsPerPage.value,
                                search: searchQuery.value
                            }
                        });
                        programs.value = response.data.programs;
                        totalRecords.value = response.data.total;
                    } catch (error) {
                        console.error('Error fetching programs:', error);
                        alert('Error loading programs. Please try again.');
                    } finally {
                        loading.value = false;
                    }
                };

                const changePage = (page) => {
                    if (page >= 1 && page <= totalPages.value) {
                        currentPage.value = page;
                        fetchPrograms();
                    }
                };

                const searchPrograms = () => {
                    clearTimeout(searchTimeout);
                    searchTimeout = setTimeout(() => {
                        currentPage.value = 1;
                        fetchPrograms();
                    }, 300);
                };

                const editProgram = (program) => {
                    window.location.href = `editProgram.php?id=${program.id}`;
                };

                onMounted(() => {
                    fetchPrograms();
                });

                return {
                    programs,
                    currentPage,
                    totalRecords,
                    itemsPerPage,
                    loading,
                    searchQuery,
                    totalPages,
                    displayedPages,
                    fetchPrograms,
                    changePage,
                    searchPrograms,
                    editProgram
                };
            }
        }).mount('#app');
    </script>
</body>
</html> 