import { useState, useEffect } from 'react';
import {
    Box,
    Title,
    Text,
    Group,
    Button,
    Modal,
    TextInput,
    Stack,
    Switch,
    Badge,
    Loader,
    Alert,
    ActionIcon,
    Menu,
    useMantineTheme,
} from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import {
    IconPlus,
    IconDots,
    IconTrash,
    IconEdit,
    IconCloud,
} from '@tabler/icons-react';

export function DynDnsTab() {
    const theme = useMantineTheme();
    const [configs, setConfigs] = useState([]);
    const [providers, setProviders] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [opened, { open: openModal, close: closeModal }] = useDisclosure(false);
    const [editingConfig, setEditingConfig] = useState(null);
    const [deleteConfirm, setDeleteConfirm] = useState(null);

    const [formData, setFormData] = useState({
        provider: 'novanas',
        name: '',
        subdomain: '',
        token: '',
        interval_minutes: 5,
        is_enabled: true,
    });

    const [modalError, setModalError] = useState(null);

    useEffect(() => {
        fetchConfigs();
    }, []);

    const fetchConfigs = async () => {
        try {
            setLoading(true);
            const response = await fetch('/api/dyndns/configs');
            const data = await response.json();

            setConfigs(data.configs || []);
            setProviders(data.available_providers || []);
            setError(null);
        } catch (err) {
            setError('Failed to load DynDNS configurations');
            console.error(err);
        } finally {
            setLoading(false);
        }
    };

    const handleSubmit = async (e) => {
        e.preventDefault();

        try {
            // Always use NovaNAS provider and fixed 5-minute interval
            const submitData = {
                ...formData,
                provider: 'novanas',
                interval_minutes: 5,
            };

            let url = '/api/dyndns/configs';
            const method = editingConfig ? 'PUT' : 'POST';

            if (editingConfig) {
                url = '/api/dyndns/configs/' + editingConfig.id;
            }

            console.log('Submitting to:', url, 'method:', method, 'editingConfig:', editingConfig);

            const response = await fetch(url, {
                method,
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(submitData),
            });

            const data = await response.json();

            if (!response.ok) {
                // Handle specific error codes from NovaNAS API
                if (response.status === 403) {
                    throw new Error('Maximum number of DNS records reached for this IP address. Please try again later or contact support.');
                }
                if (response.status === 409) {
                    throw new Error('This subdomain already exists in DNS. Please choose a different subdomain.');
                }
                // For 500 and other errors, try to get the error message from response
                const errorMessage = data.message || data.error || 'Failed to register DNS record.';
                throw new Error(errorMessage);
            }

            await fetchConfigs();
            closeModal();
            resetForm();
        } catch (err) {
            setModalError(err.message);
        }
    };

    const handleDelete = async (id) => {
        try {
            const response = await fetch(`/api/dyndns/configs/${id}`, {
                method: 'DELETE',
            });

            if (!response.ok) {
                const data = await response.json();
                throw new Error(data.message || 'Delete failed');
            }

            await fetchConfigs();
            setDeleteConfirm(null);
        } catch (err) {
            setError(err.message);
        }
    };

    const openEditModal = (config) => {
        setEditingConfig(config);
        setModalError(null);
        setFormData({
            provider: config.provider,
            name: config.name,
            subdomain: config.subdomain,
            token: '',
            interval_minutes: 5,
            is_enabled: config.is_enabled,
        });
        openModal();
    };

    const resetForm = () => {
        setEditingConfig(null);
        setModalError(null);
        setFormData({
            provider: 'novanas',
            name: '',
            subdomain: '',
            token: '',
            interval_minutes: 5,
            is_enabled: true,
        });
    };

    const openCreateModal = () => {
        resetForm();
        openModal();
    };

    const providerOptions = providers.map((p) => ({
        value: p.key,
        label: p.name,
    }));

    if (loading) {
        return (
            <Box style={{ display: 'flex', justifyContent: 'center', alignItems: 'center', height: '100%' }}>
                <Loader size="lg" />
            </Box>
        );
    }

    return (
        <Box>
            <Group justify="space-between" mb="lg">
                <div>
                    <Title order={3} c="white">DynDNS</Title>
                    <Text size="sm" c="dimmed">Manage your dynamic DNS configurations</Text>
                </div>
                <Button
                    leftSection={<IconPlus size={16} />}
                    onClick={openCreateModal}
                >
                    Add Configuration
                </Button>
            </Group>

            {error && (
                <Alert
                    color="red"
                    variant="light"
                    mb="md"
                    onClose={() => setError(null)}
                    withCloseButton
                    styles={{
                        root: {
                            backgroundColor: 'rgba(227, 48, 56, 0.15)',
                            borderColor: 'rgba(227, 48, 56, 0.5)',
                        },
                        message: {
                            color: '#ff9999',
                        },
                        title: {
                            color: '#ff9999',
                        },
                    }}
                >
                    <Text c="#ff9999">{error}</Text>
                </Alert>
            )}

            {configs.length === 0 ? (
                <Box
                    style={{
                        backgroundColor: theme.colors.dark[6],
                        borderRadius: '12px',
                        padding: '40px',
                        textAlign: 'center',
                        border: `1px solid ${theme.colors.dark[4]}`,
                    }}
                >
                    <Group justify="center" mb="md">
                        <IconCloud size={48} color="gray" />
                    </Group>
                    <Text c="dimmed" size="lg" mb="md">No DynDNS configurations</Text>
                    <Text c="dimmed" size="sm" mb="lg">
                        Add a configuration to keep your dynamic DNS updated
                    </Text>
                    <Button leftSection={<IconPlus size={16} />} onClick={openCreateModal}>
                        Add Your First Configuration
                    </Button>
                </Box>
            ) : (
                <Stack gap="md">
                    {configs.map((config) => (
                        <Box
                            key={config.id}
                            style={{
                                backgroundColor: theme.colors.dark[6],
                                borderRadius: '12px',
                                padding: '20px',
                                border: `1px solid ${theme.colors.dark[4]}`,
                            }}
                        >
                            <Group justify="space-between" wrap="nowrap">
                                <Group gap="md" wrap="nowrap">
                                    <Box
                                        style={{
                                            width: '48px',
                                            height: '48px',
                                            borderRadius: '12px',
                                            backgroundColor: theme.colors.blue[6],
                                            display: 'flex',
                                            alignItems: 'center',
                                            justifyContent: 'center',
                                        }}
                                    >
                                        <IconCloud size={24} color="white" />
                                    </Box>
                                    <div>
                                        <Group gap="sm">
                                            <Text fw={600} size="lg" c="white">{config.name}</Text>
                                            <Badge
                                                color={config.is_enabled ? 'green' : 'gray'}
                                                variant="light"
                                                size="sm"
                                            >
                                                {config.is_enabled ? 'Enabled' : 'Disabled'}
                                            </Badge>
                                        </Group>
                                        <Text size="sm" c="dimmed">
                                            {config.full_domain} • {config.provider_display_name}
                                        </Text>
                                        <Text size="xs" c="dimmed">
                                            Updates every {config.interval_minutes} minute{config.interval_minutes !== 1 ? 's' : ''}
                                            {config.last_updated_at && (
                                                <> • Last updated: {new Date(config.last_updated_at).toLocaleString()}</>
                                            )}
                                        </Text>
                                    </div>
                                </Group>

                                <Group gap="sm" wrap="nowrap">
                                    <Menu shadow="md" width={150} position="bottom-end">
                                        <Menu.Target>
                                            <ActionIcon variant="subtle" color="gray" size="lg">
                                                <IconDots size={18} />
                                            </ActionIcon>
                                        </Menu.Target>

                                        <Menu.Dropdown>
                                            <Menu.Item
                                                leftSection={<IconEdit size={14} />}
                                                onClick={() => openEditModal(config)}
                                            >
                                                Edit
                                            </Menu.Item>
                                            <Menu.Item
                                                color="red"
                                                leftSection={<IconTrash size={14} />}
                                                onClick={() => setDeleteConfirm(config.id)}
                                            >
                                                Delete
                                            </Menu.Item>
                                        </Menu.Dropdown>
                                    </Menu>
                                </Group>
                            </Group>
                        </Box>
                    ))}
                </Stack>
            )}

            <Modal
                opened={opened}
                onClose={closeModal}
                title={editingConfig ? 'Edit DynDNS Configuration' : 'Add DynDNS Configuration'}
                size="md"
            >
                <form onSubmit={(e) => { e.preventDefault(); handleSubmit(e); }}>
                    <Stack gap="md">
                        {modalError && (
                            <Alert
                                color="red"
                                variant="light"
                                styles={{
                                    root: {
                                        backgroundColor: 'rgba(227, 48, 56, 0.15)',
                                        borderColor: 'rgba(227, 48, 56, 0.5)',
                                    },
                                    message: {
                                        color: '#ff9999',
                                    },
                                    title: {
                                        color: '#ff9999',
                                    },
                                }}
                            >
                                <Text c="#ff9999">{modalError}</Text>
                            </Alert>
                        )}

                        <TextInput
                            label="Name"
                            placeholder="My NovaNAS DDNS"
                            value={formData.name}
                            onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                            required
                        />

                        <TextInput
                            label="Subdomain"
                            placeholder="yourdomain"
                            value={formData.subdomain}
                            onChange={(e) => setFormData({ ...formData, subdomain: e.target.value })}
                            required
                            rightSection={<span style={{ color: '#8b8b8b', fontSize: '14px' }}>.novanas.org</span>}
                            rightSectionWidth={90}
                        />

                        <Switch
                            label="Enabled"
                            checked={formData.is_enabled}
                            onChange={(e) => setFormData({ ...formData, is_enabled: e.target.checked })}
                        />

                        <Group justify="flex-end" mt="md">
                            <Button variant="subtle" onClick={closeModal}>
                                Cancel
                            </Button>
                            <Button type="submit">
                                {editingConfig ? 'Save Changes' : 'Create'}
                            </Button>
                        </Group>
                    </Stack>
                </form>
            </Modal>

            {/* Delete Confirmation Modal */}
            <Modal
                opened={!!deleteConfirm}
                onClose={() => setDeleteConfirm(null)}
                title="Delete Configuration"
                size="sm"
            >
                <Text c="dimmed" mb="lg">
                    Are you sure you want to delete this DynDNS configuration? This action cannot be undone.
                </Text>
                <Group justify="flex-end">
                    <Button variant="subtle" onClick={() => setDeleteConfirm(null)}>
                        Cancel
                    </Button>
                    <Button color="red" onClick={() => handleDelete(deleteConfirm)}>
                        Delete
                    </Button>
                </Group>
            </Modal>
        </Box>
    );
}
