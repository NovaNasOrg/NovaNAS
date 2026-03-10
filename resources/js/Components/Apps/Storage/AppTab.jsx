import { useEffect, useState } from 'react';
import {
    Box,
    Text,
    Group,
    LoadingOverlay,
    ThemeIcon,
    ActionIcon,
    Button,
    Modal,
    Stack,
    Badge,
    ScrollArea,
    Alert,
} from '@mantine/core';
import { useMantineTheme } from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import {
    IconFolder,
    IconApps,
    IconArrowRight,
    IconFolderOpen,
    IconAlertCircle,
} from '@tabler/icons-react';

const SETTINGS_KEYS = {
    userFilesHome: 'storage.user_files_home',
    appFoldersHome: 'storage.app_folders_home',
};

function DirectorySelectModal({ opened, onClose, onSelect, pools = [], loadingPools = false }) {
    const theme = useMantineTheme();
    const [selectedPool, setSelectedPool] = useState(null);
    const [directories, setDirectories] = useState([]);
    const [loadingDirs, setLoadingDirs] = useState(false);
    const [error, setError] = useState(null);

    const fetchDirectories = async (poolName) => {
        if (!poolName) return;

        setLoadingDirs(true);
        setError(null);
        setDirectories([]);

        try {
            const response = await fetch(`/api/storage/pools/${encodeURIComponent(poolName)}/directories`, {
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
                },
            });
            const data = await response.json();
            setDirectories(data.directories || []);
        } catch (err) {
            setError('Failed to load directories');
            console.error('Error fetching directories:', err);
        } finally {
            setLoadingDirs(false);
        }
    };

    const handlePoolSelect = (pool) => {
        setSelectedPool(pool);
        fetchDirectories(pool.name);
    };

    const handleDirectorySelect = (dir) => {
        if (selectedPool) {
            onSelect({
                pool: selectedPool.name,
                path: dir.path,
            });
            handleClose();
        }
    };

    const handleClose = () => {
        setSelectedPool(null);
        setDirectories([]);
        setError(null);
        onClose();
    };

    return (
        <Modal
            opened={opened}
            onClose={handleClose}
            title="Select Directory Location"
            size="lg"
            centered
        >
            <Stack gap="md">
                <Text size="sm" c="dimmed">
                    Select a storage pool first, then choose a directory within it.
                </Text>

                {loadingPools ? (
                    <LoadingOverlay visible={true} />
                ) : pools.length === 0 ? (
                    <Alert icon={<IconAlertCircle size={16} />} color="yellow">
                        No storage pools available. Create a pool first.
                    </Alert>
                ) : (
                    <>
                        {/* Pool Selection */}
                        {!selectedPool && (
                            <Box>
                                <Text size="sm" fw={600} mb="sm">Available Storage Pools</Text>
                                <Stack gap="xs">
                                    {pools.map((pool) => (
                                        <Box
                                            key={pool.name}
                                            onClick={() => handlePoolSelect(pool)}
                                            style={{
                                                backgroundColor: theme.colors.dark[6],
                                                borderRadius: '8px',
                                                padding: '12px 16px',
                                                cursor: 'pointer',
                                                border: `1px solid ${theme.colors.dark[4]}`,
                                                transition: 'all 0.15s ease',
                                            }}
                                        >
                                            <Group justify="space-between">
                                                <Group gap="sm">
                                                    <ThemeIcon size="md" radius="md" color="blue" variant="light">
                                                        <IconFolder size={16} />
                                                    </ThemeIcon>
                                                    <Box>
                                                        <Text size="sm" fw={600}>{pool.name}</Text>
                                                        <Text size="xs" c="dimmed">{pool.mountpoint}</Text>
                                                    </Box>
                                                </Group>
                                                <Badge
                                                    size="sm"
                                                    color={pool.health?.toUpperCase() === 'ONLINE' ? 'green' : 'yellow'}
                                                    variant="light"
                                                >
                                                    {pool.health || 'Unknown'}
                                                </Badge>
                                            </Group>
                                        </Box>
                                    ))}
                                </Stack>
                            </Box>
                        )}

                        {/* Directory Selection */}
                        {selectedPool && (
                            <Box>
                                <Group justify="space-between" mb="sm">
                                    <Group gap="xs">
                                        <ActionIcon
                                            variant="subtle"
                                            onClick={() => setSelectedPool(null)}
                                        >
                                            <IconArrowRight size={16} style={{ transform: 'rotate(180deg)' }} />
                                        </ActionIcon>
                                        <Text size="sm" fw={600}>
                                            Directories in {selectedPool.name}
                                        </Text>
                                    </Group>
                                </Group>

                                {loadingDirs ? (
                                    <LoadingOverlay visible={true} />
                                ) : error ? (
                                    <Alert icon={<IconAlertCircle size={16} />} color="red">
                                        {error}
                                    </Alert>
                                ) : directories.length === 0 ? (
                                    <Alert icon={<IconAlertCircle size={16} />} color="yellow">
                                        No directories found in this pool. Create a directory first.
                                    </Alert>
                                ) : (
                                    <ScrollArea.Autosize mah={300}>
                                        <Stack gap="xs">
                                            {directories.map((dir) => (
                                                <Box
                                                    key={dir.path}
                                                    onClick={() => handleDirectorySelect(dir)}
                                                    style={{
                                                        backgroundColor: theme.colors.dark[6],
                                                        borderRadius: '8px',
                                                        padding: '12px 16px',
                                                        cursor: 'pointer',
                                                        border: `1px solid ${theme.colors.dark[4]}`,
                                                        transition: 'all 0.15s ease',
                                                    }}
                                                >
                                                    <Group gap="sm">
                                                        <ThemeIcon size="md" radius="md" color="green" variant="light">
                                                            <IconFolderOpen size={16} />
                                                        </ThemeIcon>
                                                        <Box>
                                                            <Text size="sm" fw={500}>{dir.name}</Text>
                                                            <Text size="xs" c="dimmed">{dir.path}</Text>
                                                        </Box>
                                                    </Group>
                                                </Box>
                                            ))}
                                        </Stack>
                                    </ScrollArea.Autosize>
                                )}
                            </Box>
                        )}
                    </>
                )}

                <Group justify="flex-end" mt="md">
                    <Button variant="subtle" onClick={handleClose}>
                        Cancel
                    </Button>
                </Group>
            </Stack>
        </Modal>
    );
}

function SettingRow({ icon: Icon, label, description, value, onMove }) {
    const theme = useMantineTheme();

    return (
        <Box
            style={{
                backgroundColor: theme.colors.dark[6],
                borderRadius: '12px',
                padding: '20px',
                border: `1px solid ${theme.colors.dark[4]}`,
            }}
        >
            <Group justify="space-between" align="flex-start">
                <Group gap="md">
                    <ThemeIcon size="lg" radius="md" variant="light" color="blue">
                        <Icon size={20} />
                    </ThemeIcon>
                    <Box>
                        <Text size="md" fw={600}>{label}</Text>
                        <Text size="sm" c="dimmed">{description}</Text>
                        {value && (
                            <Text size="xs" c="blue" mt="xs" fw={500}>
                                {value}
                            </Text>
                        )}
                    </Box>
                </Group>
                <Button
                    variant="light"
                    size="sm"
                    leftSection={<IconFolder size={16} />}
                    onClick={onMove}
                >
                    {value ? 'Move' : 'Set'}
                </Button>
            </Group>
        </Box>
    );
}

export function AppTab() {
    const theme = useMantineTheme();
    const [settings, setSettings] = useState({
        [SETTINGS_KEYS.userFilesHome]: null,
        [SETTINGS_KEYS.appFoldersHome]: null,
    });
    const [pools, setPools] = useState([]);
    const [loading, setLoading] = useState(true);
    const [loadingPools, setLoadingPools] = useState(false);
    const [error, setError] = useState(null);
    const [modalOpened, { open: openModal, close: closeModal }] = useDisclosure(false);
    const [editingKey, setEditingKey] = useState(null);

    const fetchSettings = async () => {
        try {
            const response = await fetch('/api/storage/settings', {
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
                },
            });
            const data = await response.json();
            if (data.settings) {
                setSettings(data.settings);
            }
        } catch (err) {
            console.error('Error fetching settings:', err);
        }
    };

    const fetchPools = async () => {
        setLoadingPools(true);
        try {
            const response = await fetch('/api/storage/pools', {
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
                },
            });
            const data = await response.json();
            setPools(data.pools || []);
        } catch (err) {
            console.error('Error fetching pools:', err);
        } finally {
            setLoadingPools(false);
        }
    };

    const saveSetting = async (key, value) => {
        try {
            const response = await fetch('/api/storage/settings', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
                },
                body: JSON.stringify({
                    settings: {
                        [key]: value,
                    },
                }),
            });

            if (response.ok) {
                setSettings((prev) => ({
                    ...prev,
                    [key]: value,
                }));
            }
        } catch (err) {
            console.error('Error saving setting:', err);
            setError('Failed to save setting');
        }
    };

    const handleOpenModal = (key) => {
        setEditingKey(key);
        fetchPools();
        openModal();
    };

    const handleSelectDirectory = ({ pool, path }) => {
        if (editingKey) {
            const fullPath = `${path}`;
            saveSetting(editingKey, fullPath);
        }
    };

    useEffect(() => {
        const loadData = async () => {
            setLoading(true);
            setError(null);
            await fetchSettings();
            setLoading(false);
        };
        loadData();
    }, []);

    return (
        <Box style={{ position: 'relative' }}>
            <LoadingOverlay visible={loading} zIndex={1000} overlayProps={{ radius: 'sm', blur: 2 }} />

            <Text size="xl" fw={700} mb="xs">Application Settings</Text>
            <Text size="sm" c="dimmed" mb="lg">
                Configure where user files and application data are stored.
            </Text>

            {error && (
                <Alert icon={<IconAlertCircle size={16} />} color="red" mb="md">
                    {error}
                </Alert>
            )}

            <Stack gap="md">
                <SettingRow
                    icon={IconFolder}
                    label="User Files Home"
                    description="Location where user home files will be stored"
                    value={settings[SETTINGS_KEYS.userFilesHome]}
                    onMove={() => handleOpenModal(SETTINGS_KEYS.userFilesHome)}
                />

                <SettingRow
                    icon={IconApps}
                    label="App Folders Home"
                    description="Location where Docker application folders will be stored"
                    value={settings[SETTINGS_KEYS.appFoldersHome]}
                    onMove={() => handleOpenModal(SETTINGS_KEYS.appFoldersHome)}
                />
            </Stack>

            <DirectorySelectModal
                opened={modalOpened}
                onClose={closeModal}
                onSelect={handleSelectDirectory}
                pools={pools}
                loadingPools={loadingPools}
            />
        </Box>
    );
}
