---
title:   "Primitive Dependencies with Simple Injector"
date:    2012-07-19
author:  Steven van Deursen
tags:    [.NET General, C#, Dependency Simple Injector]
draft:   false
---

### This article describes how to extend the Simple Injector with convension based configuration for primitive constructor arguments.

#### **UPDATE April 2017:** For a Simple Injector v4 compatible version of these code samples, please see [here](https://github.com/simpleinjector/SimpleInjector/blob/v4.0.x/src/SimpleInjector.CodeSamples/ParameterConventionExtensions.cs). 

When working with dependency injection, services (classes that contain behavior) depend on other services. The general idea is to inject those services into the constructor of the consuming service. Primitive types are no services, since they contain no behavior, and I normally advice not to mix primitive types and services in a single constructor. My advice would normally be:

1. Extract and group the primitives in their own 'configuration' type and inject that type into the service, or
2. Move those primitives to properties and use property injection.

I find property injection nice, since those primitives are almost always system configuration values and removing them from the constructor (and thus separating them from the required service dependencies) seems very clean. It does however lead to [temporal coupling](https://blog.ploeh.dk/2011/05/24/DesignSmellTemporalCoupling/).

The general consensus about property injection is however that it is supposed to be used for optional dependencies. This means that not injecting such dependency should allow the system to keep running. A connection string however is hardly ever optional, since without a connection string, it will be impossible to connect to the database. But since I don't really see those configuration values as 'real' dependencies, I personally don't mind using property injection.

Still, mixing primitives and services in the constructor can have a benefit, as explained by [Mark Seemann](https://blog.ploeh.dk/) in [his blog post about Primitive Dependencies](https://blog.ploeh.dk/2012/07/02/PrimitiveDependencies/). In that post, Mark shows how to use [convention over configuration](https://en.wikipedia.org/wiki/Convention_over_configuration) on primitive dependencies. For instance, by naming a string dependency 'xxxConnectionString', we can load the value by name 'xxx' directly from the `<connectionStrings>` section of the application's configuration file. Or an primitive dependency, who's name ends with 'AppSettings', can be retrieved directly from the `<appSettings>` section.

Personally, I'm not sure whether I would like these types of conventions, because the name of the value in the configuration file, will be tightly coupled with your code. Besides, since the DI configuration takes a hard dependency on the configuration system, it becomes much harder to have some integration tests that verify the correctness of your DI configuration. Though, I must admit that it can make the container’s configuration simpler, because you won't have to create a new configuration type, use property injection, or fallback to using a lambda expression in registering the type. So let's see how we can implement such convention over configuration feature for [Simple Injector](https://simpleinjector.org/).

Simple Injector contains extension points for changing the way constructor injection works. By default, Simple Injector disallows registering and injecting value types and strings, which is a good default, since this would promote ambiguity. The trick is to change the parameter parameter verification behavior (defined by the IConstructorVerificationBehavior interface) and the constructor injection behavior (defined by the IConstructorInjectionBehavior).

By replacing the default implementations of these abstractions, we can extend Simple Injector to allow convention over configuration.

Let's start by defining an abstraction for conventions on constructor parameters:


{{< highlight csharp >}}
public interface IParameterConvention
{
    bool CanResolve(InjectionTargetInfo target);
    Expression BuildExpression(InjectionConsumerInfo consumer);
}
{{< / highlight >}}

This interface implements the tester-doer pattern. We can ask the convention whether it can resolve the supplied injection target, and if it can, `BuildExpression` allows us to create an `Expression` object that defines the constructor argument. Simple Injector works with expression trees under the covers, which allows it to compile delegates with performance that is very close to newing types up manually. By letting a convention return an `Expression`, we will have best performance, and most flexibility in what and how a parameter must be injected.

Mark Seemann uses a convention for connection strings and app settings. Let's stick with that example and those two conventions. Let's start with the `ConnectionStringsConvention`:


{{< highlight csharp >}}
public class ConnectionStringsConvention : IParameterConvention
{
    private const string ConnectionStringPostFix = "ConnectionString";

    [DebuggerStepThrough]
    public bool CanResolve(InjectionTargetInfo target)
    {
        bool resolvable =
            target.TargetType == typeof(string) &&
            target.Name.EndsWith(ConnectionStringPostFix) &&
            target.Name.LastIndexOf(ConnectionStringPostFix) > 0;

        return resolvable
            ? this.VerifyConfigurationFile(target)
            : resolvable;
    }

    [DebuggerStepThrough]
    public Expression BuildExpression(
        InjectionConsumerInfo consumer)
    {
        string connectionString =
            GetConnectionString(consumer.Target);

        return Expression.Constant(connectionString,
            typeof(string));
    }

    [DebuggerStepThrough]
    private void VerifyConfigurationFile(
        InjectionTargetInfo target)
    {
        GetConnectionString(target);
    }

    [DebuggerStepThrough]
    private static string GetConnectionString(
        InjectionTargetInfo target)
    {
        string name = target.Name.Substring(0,
            target.Name.LastIndexOf(ConnectionStringPostFix));

        var settings =
            ConfigurationManager.ConnectionStrings[name];

        if (settings == null)
        {
            throw new ActivationException(
                $"No connection string with name '{name}'" +
                "could be found in the application's " + 
                "configuration file.");
        }

        return settings.ConnectionString;
    }
}
{{< / highlight >}}

This `ConnectionStringsConvention` does a few interesting things. Its `CanResolve` method checks to see if the supplied injection target is of type `string` and its name ends with 'ConnectionString'. If not, `CanResolve` returns `false` immediately, which means that we can fall back on Simple Injector’s default validation behavior (or any behavior that is has been defined previously). If the target matches, `CanResolve` will check if the value can be found in the `<connectionStrings>` section of the application's configuration file. An exception will be thrown when this is not the case. The `CanResolve` will get called during the registration process, and throwing an exception therefore allows us to let the application fail immediately when an invalid registration is made.

Compared to the `CanResolve`, the `BuildExpression` method pretty simple. It retrieves the connection string value from the configuration file, wraps it in an expression and returns that expression. Since the configuration file can't change during the lifetime of an application (changes either have no effect, or in case of a web application, will cause the application to be restarted), it would be useless to reread the value every time a new instance of the depending type is created. The value is constant, and we can safely return a `ConstantExpression`. This also yields optimal performance.

The `AppSettingsConvention` looks similar to the previous `ConnectionStringsConvention`. It too checks to see if the value exists in the configuration file. However, while the `ConnectionStringsConvention` would only deal with strings, the `AppSettingsConvention` can work with strings and any arbitrary value type that can be converted from a string (using .NET’s built-in `TypeConverter` system):

{{< highlight csharp >}}
public class AppSettingsConvention : IParameterConvention
{
    private const string AppSettingsPostFix = "AppSetting";

    [DebuggerStepThrough]
    public bool CanResolve(InjectionTargetInfo target)
    {
        Type type = target.TargetType;

        bool resolvable =
            (type.IsValueType || type == typeof(string)) &&
            target.Name.EndsWith(AppSettingsPostFix) &&
            target.Name.LastIndexOf(AppSettingsPostFix) > 0;

        if (resolvable)
        {
            this.VerifyConfigurationFile(target);
        }

        return resolvable;
    }

    [DebuggerStepThrough]
    public Expression BuildExpression(InjectionConsumerInfo consumer)
    {
        object valueToInject = GetAppSettingValue(consumer.Target);
        return Expression.Constant(valueToInject, consumer.Target.TargetType);
    }

    [DebuggerStepThrough]
    private void VerifyConfigurationFile(InjectionTargetInfo target)
    {
        GetAppSettingValue(target);
    }

    [DebuggerStepThrough]
    private static object GetAppSettingValue(InjectionTargetInfo target)
    {
        string key = target.Name.Substring(0,
            target.Name.LastIndexOf(AppSettingsPostFix));

        string configurationValue = ConfigurationManager.AppSettings[key];

        if (configurationValue != null)
        {
            var converter = TypeDescriptor.GetConverter(target.TargetType);

            return converter.ConvertFromString(
                null,
                CultureInfo.InvariantCulture,
                configurationValue);
        }

        throw new ActivationException(
            "No application setting with key '{key}' could be " +
            "found in the application's configuration file.");
    }
}
{{< / highlight >}}

Now we've got two `IParameterConvention` implementations, we need to allow plugging these implementations in the Simple Injector 3 auto-wiring pipeline. All we need to do is to create a fairly trivial `IDependencyInjectionBehavior` implementation:

{{< highlight csharp >}}
internal class ConventionDependencyInjectionBehavior
    : IDependencyInjectionBehavior
{
    private readonly IDependencyInjectionBehavior decoratee;
    private readonly IParameterConvention convention;

    public ConventionDependencyInjectionBehavior(
        IDependencyInjectionBehavior decoratee,
        IParameterConvention convention)
    {
        this.decoratee = decoratee;
        this.convention = convention;
    }

    [DebuggerStepThrough]
    public Expression BuildExpression(InjectionConsumerInfo consumer)
    {
        return this.convention.CanResolve(consumer.Target)
            ? this.convention.BuildExpression(consumer)
            : this.decoratee.BuildExpression(consumer);
    }
            
    [DebuggerStepThrough]
    public void Verify(InjectionConsumerInfo consumer)
    {
        if (!this.convention.CanResolve(consumer.Target))
        {
            this.decoratee.Verify(consumer);
        }
    }
}
{{< / highlight >}}

This `ConventionDependencyInjectionBehavior` is a decorator. It extends the container's original behavior with convention support. By extending the original behavior, it allows us to apply multiple conventions, or even mix it with other plug-ins that changed the default behavior of the container.

Just one thing is missing, and that is a convenient extension method, that makes registering a new `IParameterConvention` a simple one-liner:


{{< highlight csharp >}}
public static void RegisterParameterConvention(
    this ContainerOptions options, IParameterConvention convention)
{
    options.DependencyInjectionBehavior =
        new ConventionDependencyInjectionBehavior(
            options.DependencyInjectionBehavior, 
            convention);
}
{{< / highlight >}}

This extension method works over the `ContainerOptions` class and replaces the ContainerOptions' original `DependencyInjectionBehavior` with our specially crafted version, while wrapping the original implementation. With this in place, we can use these conventions as follows:


{{< highlight csharp >}}
var container = new Container();

// Add the parameter convensions:
container.Options.RegisterParameterConvention(
    new ConnectionStringsConvention());
container.Options.RegisterParameterConvention(
    new AppSettingsConvention());

// Registrations here
container.Register<IDbContext, MyDbContext>();
{{< / highlight >}}

And there you have it. Convention support for primitive dependencies with the Simple Injector 3.

Happy injecting! 

## Comments
