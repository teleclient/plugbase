# plugbase

A Pluggable Base for MadelineProto Library 

The program's core accepts two types of plugins; event-handler plugins, and repeating task plugins.

Event-Handler plugins

Each event-handler plugin extends an abstract class which provides basic services to plugins which minimimizes the efforts for writing a new plugin.

Each plugin must provide a method __invokes which is invoked on each individual incomming telegram update message. An example of a working plugin is as follows:

  class GreetingHandler extends Abstract handler implements Handler
  {
  
  

