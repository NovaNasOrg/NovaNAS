import {
    Box,
    Button,
    Container,
    Group,
    Paper,
    Select,
    Stack,
    Text,
    Title,
    rem,
} from '@mantine/core';
import { IconCloudComputing, IconArrowRight, IconUser } from '@tabler/icons-react';
import { useForm } from '@inertiajs/react';

const STEPS = [
    { id: 1, title: 'Welcome', description: 'Get started' },
    { id: 2, title: 'Account', description: 'Create admin account' },
    { id: 3, title: 'Linux User', description: 'Bind to system user' },
];

export default function WizardBindUser({ errors, linuxUsers = [] }) {
    const { data, setData, post, processing } = useForm({
        username: '',
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        post('/wizard/bind-user');
    };

    return (
        <Box
            style={{
                minHeight: '100vh',
                width: '100%',
                position: 'relative',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
            }}
        >
            {/* Background Image with Overlay */}
            <Box
                style={{
                    position: 'absolute',
                    top: 0,
                    left: 0,
                    right: 0,
                    bottom: 0,
                    backgroundImage: 'url(/images/login-bg.jpeg)',
                    backgroundSize: 'cover',
                    backgroundPosition: 'center',
                    backgroundRepeat: 'no-repeat',
                }}
            />

            {/* Dark Overlay */}
            <Box
                style={{
                    position: 'absolute',
                    top: 0,
                    left: 0,
                    right: 0,
                    bottom: 0,
                    background: 'linear-gradient(135deg, rgba(0, 0, 0, 0.85) 0%, rgba(20, 25, 40, 0.9) 100%)',
                    backdropFilter: 'blur(8px)',
                }}
            />

            {/* Animated Background Elements */}
            <Box
                style={{
                    position: 'absolute',
                    top: 0,
                    left: 0,
                    right: 0,
                    bottom: 0,
                    overflow: 'hidden',
                    pointerEvents: 'none',
                }}
            >
                <Box
                    style={{
                        position: 'absolute',
                        top: '10%',
                        left: '10%',
                        width: '300px',
                        height: '300px',
                        borderRadius: '50%',
                        background: 'radial-gradient(circle, rgba(99, 102, 241, 0.15) 0%, transparent 70%)',
                        animation: 'float 8s ease-in-out infinite',
                    }}
                />
                <Box
                    style={{
                        position: 'absolute',
                        bottom: '20%',
                        right: '15%',
                        width: '400px',
                        height: '400px',
                        borderRadius: '50%',
                        background: 'radial-gradient(circle, rgba(6, 182, 212, 0.12) 0%, transparent 70%)',
                        animation: 'float 10s ease-in-out infinite reverse',
                    }}
                />
                <Box
                    style={{
                        position: 'absolute',
                        top: '50%',
                        left: '50%',
                        width: '500px',
                        height: '500px',
                        borderRadius: '50%',
                        background: 'radial-gradient(circle, rgba(139, 92, 246, 0.08) 0%, transparent 70%)',
                        transform: 'translate(-50%, -50%)',
                        animation: 'pulse 15s ease-in-out infinite',
                    }}
                />
            </Box>

            {/* Wizard Content */}
            <Container size={500} style={{ position: 'relative', zIndex: 1 }}>
                <Paper
                    shadow="xl"
                    radius="lg"
                    p={rem(40)}
                    style={{
                        background: 'rgba(255, 255, 255, 0.03)',
                        backdropFilter: 'blur(20px)',
                        border: '1px solid rgba(255, 255, 255, 0.08)',
                    }}
                >
                    <Stack align="center" gap="lg">
                        {/* Logo/Icon */}
                        <Box
                            style={{
                                width: rem(80),
                                height: rem(80),
                                borderRadius: '20px',
                                background: 'linear-gradient(135deg, #6366f1 0%, #8b5cf6 50%, #06b6d4 100%)',
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                boxShadow: '0 8px 32px rgba(99, 102, 241, 0.4)',
                            }}
                        >
                            <IconCloudComputing size={40} color="white" stroke={1.5} />
                        </Box>

                        <Title order={2} ta="center" fw={700} c="white" style={{ fontSize: rem(24), letterSpacing: '-0.5px' }}>
                            Bind Linux User
                        </Title>
                        <Text c="dimmed" size="sm" ta="center">
                            Link your NovaNAS account to an existing Linux user on this system.
                        </Text>

                        {/* Steps Indicator */}
                        <Group gap="xl">
                            {STEPS.map((step, index) => (
                                <Group key={step.id} gap="sm">
                                    <Box
                                        style={{
                                            width: rem(28),
                                            height: rem(28),
                                            borderRadius: '50%',
                                            background: index <= 2
                                                ? 'linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%)'
                                                : 'rgba(255, 255, 255, 0.1)',
                                            display: 'flex',
                                            alignItems: 'center',
                                            justifyContent: 'center',
                                            fontWeight: 600,
                                            fontSize: rem(12),
                                            color: index <= 2 ? 'white' : 'rgba(255, 255, 255, 0.5)',
                                        }}
                                    >
                                        {index + 1}
                                    </Box>
                                    {index < STEPS.length - 1 && (
                                        <Box
                                            style={{
                                                width: rem(30),
                                                height: rem(2),
                                                background: index < 2
                                                    ? 'rgba(255, 255, 255, 0.3)'
                                                    : 'rgba(255, 255, 255, 0.1)',
                                            }}
                                        />
                                    )}
                                </Group>
                            ))}
                        </Group>

                        {/* Bind User Form */}
                        <form onSubmit={handleSubmit} style={{ width: '100%' }}>
                            <Stack gap="md" w="100%">
                                <Select
                                    size="md"
                                    placeholder="Select a Linux user"
                                    name="username"
                                    value={data.username}
                                    onChange={(value) => setData('username', value || '')}
                                    leftSection={<IconUser size={18} stroke={1.5} />}
                                    data={linuxUsers}
                                    error={errors?.username}
                                    required
                                    searchable
                                    nothingFoundMessage="No users found"
                                    styles={{
                                        input: {
                                            background: 'rgba(255, 255, 255, 0.05)',
                                            border: '1px solid rgba(255, 255, 255, 0.1)',
                                            color: 'white',
                                            '&::placeholder': {
                                                color: 'rgba(255, 255, 255, 0.4)',
                                            },
                                            '&:focus': {
                                                borderColor: '#6366f1',
                                            },
                                        },
                                        dropdown: {
                                            background: 'rgba(30, 30, 40, 0.95)',
                                            border: '1px solid rgba(255, 255, 255, 0.1)',
                                        },
                                        option: {
                                            color: 'white',
                                            '&[dataSelected]': {
                                                background: '#6366f1',
                                            },
                                            '&:hover': {
                                                background: 'rgba(99, 102, 241, 0.2)',
                                            },
                                        },
                                    }}
                                />

                                <Text c="dimmed" size="xs" ta="center">
                                    Select an existing Linux user on this system (UID greater than or equal to 1000).
                                    This will be used for file access and permissions.
                                </Text>

                                <Button
                                    type="submit"
                                    size="lg"
                                    fullWidth
                                    mt="md"
                                    loading={processing}
                                    rightSection={<IconArrowRight size={18} />}
                                    disabled={!data.username}
                                    style={{
                                        background: 'linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%)',
                                        border: 'none',
                                        fontWeight: 600,
                                        height: rem(48),
                                    }}
                                    styles={{
                                        root: {
                                            transition: 'all 0.3s ease',
                                            '&:hover': {
                                                transform: 'translateY(-2px)',
                                                boxShadow: '0 8px 24px rgba(99, 102, 241, 0.4)',
                                            },
                                            '&:disabled': {
                                                background: 'rgba(99, 102, 241, 0.5)',
                                            },
                                        },
                                    }}
                                >
                                    Continue
                                </Button>
                            </Stack>
                        </form>

                        {/* Navigation Info */}
                        <Text c="dimmed" size="xs" ta="center">
                            You can change this binding later in Settings.
                        </Text>
                    </Stack>
                </Paper>
            </Container>

            {/* Keyframe Animations */}
            <style>{`
                @keyframes float {
                    0%, 100% { transform: translateY(0) rotate(0deg); }
                    50% { transform: translateY(-20px) rotate(5deg); }
                }
                @keyframes pulse {
                    0%, 100% { transform: translate(-50%, -50%) scale(1); opacity: 0.5; }
                    50% { transform: translate(-50%, -50%) scale(1.1); opacity: 0.8; }
                }
            `}</style>
        </Box>
    );
}
