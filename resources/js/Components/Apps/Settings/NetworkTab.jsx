import { useState, useEffect } from 'react';
import {
    Box,
    Title,
    Text,
    Group,
    Badge,
    Button,
    Modal,
    TextInput,
    Select,
    Stack,
    Alert,
    Loader,
    useMantineTheme,
} from '@mantine/core';
import { IconNetwork, IconSettings, IconAlertCircle, IconCheck } from '@tabler/icons-react';

function NetworkInterfaceCard({ interface: iface, onEdit }) {
    const theme = useMantineTheme();
    const isUp = iface.state === 'up';
    const hasIp = !!iface.ipv4;

    return (
        <Box
            style={{
                backgroundColor: theme.colors.dark[6],
                borderRadius: '12px',
                padding: '20px',
                border: `1px solid ${theme.colors.dark[4]}`,
            }}
        >
            <Group justify="space-between" wrap="wrap" gap="md">
                <Group gap="md">
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
                        <IconNetwork size={24} color="white" />
                    </Box>
                    <div>
                        <Text fw={700} size="lg" c="white">{iface.name}</Text>
                        <Text size="sm" c="dimmed">{iface.mac || 'No MAC address'}</Text>
                    </div>
                </Group>

                <Button
                    size="sm"
                    variant="light"
                    leftSection={<IconSettings size={16} />}
                    onClick={() => onEdit(iface)}
                >
                    Configure
                </Button>
            </Group>

            <Group gap="md" mt="md">
                <Badge
                    size="lg"
                    color={isUp ? 'green' : 'red'}
                    variant="light"
                    radius="sm"
                >
                    {isUp ? 'UP' : 'DOWN'}
                </Badge>
                <Badge
                    size="lg"
                    color={hasIp ? 'blue' : 'gray'}
                    variant="light"
                    radius="sm"
                >
                    {hasIp ? iface.ipv4 : 'No IP'}
                </Badge>
            </Group>

            <Text size="sm" c="dimmed" mt="sm">
                Type: {iface.type}
            </Text>
        </Box>
    );
}

function NetworkEditModal({ opened, onClose, interface: iface, onSave }) {
    const theme = useMantineTheme();
    const [method, setMethod] = useState('dhcp');
    const [ip, setIp] = useState('');
    const [netmask, setNetmask] = useState('255.255.255.0');
    const [gateway, setGateway] = useState('');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);

    useEffect(() => {
        if (iface) {
            const configMethod = iface.configMethod || (iface.ipv4 ? 'static' : 'dhcp');
            setMethod(configMethod);
            setIp(iface.configIp || iface.ipv4 || '');
            setNetmask(iface.configNetmask || '255.255.255.0');
            setGateway(iface.configGateway || '');
            setError(null);
        }
    }, [iface]);

    const handleSave = async () => {
        setSaving(true);
        setError(null);

        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            const response = await fetch('/api/network/config', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({
                    interface: iface.name,
                    method,
                    ip: method === 'static' ? ip : null,
                    netmask: method === 'static' ? netmask : null,
                    gateway: method === 'static' ? gateway : null,
                }),
            });

            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.error || 'Failed to save configuration');
            }

            onSave();
            onClose();
        } catch (err) {
            setError(err.message);
        } finally {
            setSaving(false);
        }
    };

    if (!iface) return null;

    const inputStyles = {
        input: { backgroundColor: theme.colors.dark[5], borderColor: theme.colors.dark[4], color: 'white' },
        label: { color: theme.colors.gray[3] },
    };

    return (
        <Modal
            opened={opened}
            onClose={onClose}
            title={<Text fw={600} c="white">Configure {iface.name}</Text>}
            centered
            overlayProps={{ backgroundOpacity: 0.55, blur: 3 }}
            styles={{
                content: { backgroundColor: theme.colors.dark[6] },
                header: { backgroundColor: theme.colors.dark[6] },
            }}
        >
            <Stack gap="md">
                {error && (
                    <Alert icon={<IconAlertCircle size={16} />} color="red" variant="light">
                        {error}
                    </Alert>
                )}

                <Select
                    label="IP Configuration Method"
                    value={method}
                    onChange={setMethod}
                    data={[
                        { value: 'dhcp', label: 'DHCP (Automatic)' },
                        { value: 'static', label: 'Static IP' },
                    ]}
                    styles={inputStyles}
                />

                {method === 'static' && (
                    <>
                        <TextInput
                            label="IP Address"
                            placeholder="192.168.1.100"
                            value={ip}
                            onChange={(e) => setIp(e.target.value)}
                            styles={inputStyles}
                        />
                        <TextInput
                            label="Netmask"
                            placeholder="255.255.255.0"
                            value={netmask}
                            onChange={(e) => setNetmask(e.target.value)}
                            styles={inputStyles}
                        />
                        <TextInput
                            label="Gateway"
                            placeholder="192.168.1.1"
                            value={gateway}
                            onChange={(e) => setGateway(e.target.value)}
                            styles={inputStyles}
                        />
                    </>
                )}

                <Group justify="flex-end" mt="md">
                    <Button variant="default" onClick={onClose}>Cancel</Button>
                    <Button
                        onClick={handleSave}
                        loading={saving}
                        leftSection={saving ? null : <IconCheck size={16} />}
                    >
                        Save Changes
                    </Button>
                </Group>
            </Stack>
        </Modal>
    );
}

export function NetworkTab() {
    const [interfaces, setInterfaces] = useState([]);
    const [loading, setLoading] = useState(true);
    const [selectedInterface, setSelectedInterface] = useState(null);
    const [editModalOpen, setEditModalOpen] = useState(false);

    const fetchInterfaces = async () => {
        try {
            const response = await fetch('/api/network/interfaces');
            const data = await response.json();
            setInterfaces(data);
        } catch (err) {
            console.error('Error fetching interfaces:', err);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchInterfaces();
    }, []);

    const handleEdit = async (iface) => {
        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            const response = await fetch(`/api/network/config/${iface.name}`);
            const data = await response.json();

            setSelectedInterface({
                ...iface,
                configMethod: data.method || 'dhcp',
                configIp: data.ip || '',
                configNetmask: data.netmask || '255.255.255.0',
                configGateway: data.gateway || '',
            });
            setEditModalOpen(true);
        } catch (err) {
            console.error('Error fetching interface config:', err);
            setSelectedInterface(iface);
            setEditModalOpen(true);
        }
    };

    const handleSave = () => {
        fetchInterfaces();
    };

    if (loading) {
        return (
            <Box style={{ display: 'flex', justifyContent: 'center', padding: '48px' }}>
                <Loader color="blue" />
            </Box>
        );
    }

    return (
        <Box>
            <Title order={3} mb="lg" c="white">Network Interfaces</Title>

            {interfaces.length === 0 ? (
                <Alert icon={<IconAlertCircle size={16} />} color="yellow" variant="light">
                    No network interfaces found.
                </Alert>
            ) : (
                <Stack gap="md">
                    {interfaces.map((iface) => (
                        <NetworkInterfaceCard
                            key={iface.name}
                            interface={iface}
                            onEdit={handleEdit}
                        />
                    ))}
                </Stack>
            )}

            <NetworkEditModal
                opened={editModalOpen}
                onClose={() => setEditModalOpen(false)}
                interface={selectedInterface}
                onSave={handleSave}
            />
        </Box>
    );
}
